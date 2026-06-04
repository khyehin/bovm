<?php
// public/admin/reports/transaction_report_export.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
  require_perm('REPORT.V');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

/**
 * 银行 label（跟 txn_list 一样）
 */
function bank_label(array $b): string {
  $parts = [];
  if (!empty($b['bank_code']))     $parts[] = $b['bank_code'];
  if (!empty($b['account_name']))  $parts[] = $b['account_name'];
  if (!empty($b['account_no']))    $parts[] = $b['account_no'];
  $label = implode(' · ', $parts);
  if (!empty($b['currency'])) {
    $label .= $label !== '' ? ' ['.$b['currency'].']' : '['.$b['currency'].']';
  }
  return $label ?: ('Account #'.($b['id'] ?? ''));
}

// ------------------------------
// Filters（跟 transaction_report.php 一致）
// ------------------------------
$date_from   = $_GET['date_from'] ?? '';
$date_to     = $_GET['date_to']   ?? '';
$customer_id = (int)($_GET['customer_id'] ?? 0);

$type   = strtoupper(trim($_GET['type']   ?? 'ALL'));     // ALL/IN/OUT
$status = strtoupper(trim($_GET['status'] ?? 'ALL'));     // ALL/DRAFT/SENT/PENDING/CONFIRMED
$method = (string)($_GET['method'] ?? 'ALL');             // ALL/BANK_<id>/ALLOCATE
$contra = strtoupper(trim($_GET['contra'] ?? 'WITHOUT')); // ALL/CONTRA/WITHOUT
$q      = trim((string)($_GET['q'] ?? ''));

if ($date_from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if ($date_to   === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-t');

if (!in_array($type, ['ALL','IN','OUT'], true)) $type = 'ALL';
if (!in_array($status, ['ALL','DRAFT','SENT','PENDING','CONFIRMED'], true)) $status = 'ALL';
if (!in_array($contra, ['ALL','CONTRA','WITHOUT'], true)) $contra = 'WITHOUT';

// 日期表达式：优先 txn_date，没有就 created_at
$dateExpr = "COALESCE(t.txn_date, DATE(t.created_at))";

// ------------------------------
// Bank accounts map（用来显示 method label）
// ------------------------------
$bankRows = [];
try {
  $bankRows = $pdo->query("
    SELECT id, bank_code, account_name, account_no, currency
    FROM company_bank_accounts
    WHERE is_active = 1
    ORDER BY bank_code, account_name, account_no, id
  ")->fetchAll();
} catch (Throwable $e) {
  $bankRows = [];
}
$bankAccMap = [];
foreach ($bankRows as $b) $bankAccMap[(int)$b['id']] = $b;

// parse method
$bankFilterId = 0;
$onlyContra   = false;
if (is_string($method) && strpos($method, 'BANK_') === 0) {
  $bankFilterId = (int)substr($method, 5);
  if ($bankFilterId <= 0) $bankFilterId = 0;
} elseif ($method === 'ALLOCATE') {
  $onlyContra = true;
}

// ------------------------------
// Build WHERE（完全对齐 transaction_report.php）
// ------------------------------
$where  = [];
$params = [];

$where[] = "{$dateExpr} BETWEEN :d1 AND :d2";
$params[':d1'] = $date_from;
$params[':d2'] = $date_to;

if ($customer_id > 0) {
  $where[] = "t.customer_id = :cid";
  $params[':cid'] = $customer_id;
}

if ($type === 'IN')  $where[] = "t.txn_type = 'IN'";
if ($type === 'OUT') $where[] = "t.txn_type = 'OUT'";

if (in_array($status, ['DRAFT','SENT','PENDING','CONFIRMED'], true)) {
  $where[] = "t.status = :status";
  $params[':status'] = $status;
}

// method filter：BANK_<id> / ALLOCATE（跟 txn_list）
if ($onlyContra) {
  $where[] = "(t.is_contra = 1)";
} elseif ($bankFilterId > 0) {
  $where[] = "(
      (t.txn_type = 'IN' AND EXISTS (
        SELECT 1
        FROM customer_txn_payments p
        WHERE p.customer_txn_id = t.id
          AND p.bank_account_id = :bank_filter_id
      ))
      OR
      (t.txn_type = 'OUT' AND COALESCE(t.bank_account_id,0) = :bank_filter_id)
    )";
  $params[':bank_filter_id'] = $bankFilterId;
}

// contra dropdown（保留 report 的 ALL/CONTRA/WITHOUT）
if (!$onlyContra) {
  if ($contra === 'CONTRA') {
    $where[] = "(
      (t.is_contra = 1)
      OR (t.txn_type = 'IN' AND t.source_txn_id IS NOT NULL AND t.source_txn_id <> 0)
    )";
  } elseif ($contra === 'WITHOUT') {
    $where[] = "(
      (t.is_contra IS NULL OR t.is_contra = 0)
      AND NOT (
        t.txn_type = 'IN'
        AND COALESCE(t.allocated_amount,0) >= t.amount
      )
    )";
  }
}

// search：title / ref_no / remark
if ($q !== '') {
  $where[] = "(t.title LIKE :q OR t.ref_no LIKE :q OR t.remark LIKE :q)";
  $params[':q'] = '%'.$q.'%';
}

$whereSql = $where ? implode(' AND ', $where) : '1=1';

// ------------------------------
// 取数据（导出不分页：全部）
// ------------------------------
$sql = "
  SELECT
    t.*,
    c.code AS customer_code,
    c.name AS customer_name,
    DATE({$dateExpr}) AS txn_effective_date
  FROM customer_txn t
  LEFT JOIN customers c ON c.id = t.customer_id
  WHERE {$whereSql}
  ORDER BY txn_effective_date DESC, t.id DESC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// ------------------------------
// payments 汇总（给 invoice 的 method display + paid/pending）
// ------------------------------
$paidRawByTxn = []; // tid => sum(amount)
$bankIdsByTxn = []; // tid => [bank_id=>true]
$txnIds = [];

foreach ($rows as $r) $txnIds[(int)$r['id']] = true;

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
    while ($p = $st->fetch()) {
      $tid = (int)$p['customer_txn_id'];
      $amt = (float)($p['amount'] ?? 0);
      if (!isset($paidRawByTxn[$tid])) $paidRawByTxn[$tid] = 0.0;
      $paidRawByTxn[$tid] += $amt;

      $bid = (int)($p['bank_account_id'] ?? 0);
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

// ------------------------------
// Summary（完全照 txn_list CASE）
// ------------------------------
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
  WHERE {$whereSql}
";
$st = $pdo->prepare($sumSql);
$st->execute($params);
$sumRow = $st->fetch() ?: [];

$total_in_normal  = (float)($sumRow['total_in_normal'] ?? 0);
$total_out_normal = (float)($sumRow['total_out_normal'] ?? 0);
$bonus_total      = (float)($sumRow['bonus_total'] ?? 0);
$repay_total      = (float)($sumRow['repay_total'] ?? 0);
$loan_total       = (float)($sumRow['loan_total'] ?? 0);

$net_normal     = $total_in_normal - $total_out_normal;
$return_balance = $loan_total - $repay_total;

$summary_in     = $total_in_normal + $bonus_total + $repay_total;
$summary_out    = $total_out_normal + $loan_total;
$summary_net    = $summary_in - $summary_out;

// pending total（金额）
$pending_total = 0.0;
foreach ($rows as $r) {
  if (($r['txn_type'] ?? '') !== 'IN') continue;
  if ((int)($r['is_contra'] ?? 0) === 1) continue;

  $inKind = strtoupper((string)($r['in_kind'] ?? 'INVOICE'));
  if ($inKind !== 'INVOICE') continue;

  if (($r['status'] ?? '') === 'CONFIRMED') continue;

  $tid = (int)$r['id'];
  $order_total = (float)($r['order_total'] ?? 0);
  $paid_raw = (float)($paidRawByTxn[$tid] ?? 0);
  $unpaid = $order_total - $paid_raw;

  if ($unpaid > 0.0001) $pending_total += $unpaid;
}

// ------------------------------
// Audit
// ------------------------------
if (function_exists('audit_log')) {
  audit_log(
    $pdo,
    'REPORT.TRANSACTION.EXPORT',
    [
      'description' => 'Export transaction report',
      'date_from'   => $date_from,
      'date_to'     => $date_to,
      'customer_id' => $customer_id ?: null,
      'type'        => $type,
      'status'      => $status,
      'method'      => $method,
      'contra'      => $contra,
      'q'           => $q !== '' ? $q : null,
    ],
    'report_transaction',
    null
  );
}

$nowSlug      = date('Ymd_His');
$filenameBase = "transaction_report_{$nowSlug}";

// ------------------------------
// CSV fallback (no PhpSpreadsheet)
// ------------------------------
if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$filenameBase.'.csv"');
  header('Cache-Control: max-age=0');

  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

  fputcsv($out, ["Transaction Report"]);
  fputcsv($out, ["Period: {$date_from} to {$date_to}"]);
  fputcsv($out, []);

  // Summary block
  fputcsv($out, ["SUMMARY (After contra)"]);
  fputcsv($out, ["Total IN", number_format($total_in_normal, 2, '.', '')]);
  fputcsv($out, ["Total OUT", number_format($total_out_normal, 2, '.', '')]);
  fputcsv($out, ["Net", number_format($net_normal, 2, '.', '')]);
  fputcsv($out, ["Pending (invoice)", number_format($pending_total, 2, '.', '')]);
  fputcsv($out, ["Return balance", number_format($return_balance, 2, '.', '')]);
  fputcsv($out, ["Bonus", number_format($bonus_total, 2, '.', '')]);
  fputcsv($out, ["Summary IN", number_format($summary_in, 2, '.', '')]);
  fputcsv($out, ["Summary OUT", number_format($summary_out, 2, '.', '')]);
  fputcsv($out, ["Summary Net", number_format($summary_net, 2, '.', '')]);
  fputcsv($out, []);

  $header = [
    'Date',
    'Customer Code',
    'Customer Name',
    'Type',
    'Kind',
    'Contra',
    'Method (Bank)',
    'Status',
    'Currency',
    'Amount (signed)',
    'Invoice Total',
    'Paid Raw',
    'Pending',
    'Ref No',
    'Title',
    'Notes',
  ];
  fputcsv($out, $header);

  foreach ($rows as $r) {
    $tid = (int)$r['id'];
    $date = $r['txn_effective_date'] ?? ($r['txn_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10));

    $txnType = (string)($r['txn_type'] ?? '');
    $isOut = ($txnType === 'OUT');
    $isContra = ((int)($r['is_contra'] ?? 0) === 1);

    $inKind = strtoupper((string)($r['in_kind'] ?? ''));
    $outKind = strtoupper((string)($r['out_kind'] ?? ''));
    $kind = $txnType === 'IN' ? ($inKind ?: 'IN') : ($outKind ?: 'OUT');

    // amount display logic（invoice 用 order_total 只是显示，不影响 signed amount）
    $amount = (float)($r['amount'] ?? 0);
    $signedAmount = $isOut ? -$amount : $amount;

    $txnCurrency = (string)($r['currency'] ?? 'MYR');
    $orderCurrency = (string)($r['order_currency'] ?? '');
    $invoiceTotal = 0.0;

    if ($txnType === 'IN' && strtoupper((string)($r['in_kind'] ?? 'INVOICE')) === 'INVOICE') {
      $invoiceTotal = (float)($r['order_total'] ?? 0);
      $displayCurrency = $orderCurrency !== '' ? $orderCurrency : $txnCurrency;
    } else {
      $displayCurrency = $txnCurrency;
    }

    $paid_raw = (float)($paidRawByTxn[$tid] ?? 0);
    $pending = 0.0;
    if ($txnType === 'IN' && strtoupper((string)($r['in_kind'] ?? 'INVOICE')) === 'INVOICE' && !$isContra && ($r['status'] ?? '') !== 'CONFIRMED') {
      $pending = max(0.0, $invoiceTotal - $paid_raw);
    }

    // method label: invoice -> payment bank(s); out -> bank_account_id
    $methodLabel = '-';
    $bankLabels = [];

    if ($txnType === 'IN' && strtoupper((string)($r['in_kind'] ?? 'INVOICE')) === 'INVOICE') {
      if (!empty($bankIdsByTxn[$tid])) {
        foreach (array_keys($bankIdsByTxn[$tid]) as $bid) {
          if (isset($bankAccMap[(int)$bid])) $bankLabels[] = bank_label($bankAccMap[(int)$bid]);
        }
      }
    } elseif ($txnType === 'OUT') {
      $outBankId = (int)($r['bank_account_id'] ?? 0);
      if ($outBankId > 0 && isset($bankAccMap[$outBankId])) $bankLabels[] = bank_label($bankAccMap[$outBankId]);
    }

    $bankLabels = array_values(array_unique(array_filter($bankLabels, 'strlen')));
    if ($bankLabels) $methodLabel = implode(' / ', $bankLabels);
    else {
      $m = strtoupper((string)($r['method'] ?? ''));
      $methodLabel = $m !== '' ? $m : '-';
    }

    fputcsv($out, [
      $date,
      $r['customer_code'] ?? '',
      $r['customer_name'] ?? '',
      $txnType,
      $kind,
      $isContra ? 'YES' : 'NO',
      $methodLabel,
      $r['status'] ?? '',
      $displayCurrency,
      number_format($signedAmount, 2, '.', ''),
      number_format($invoiceTotal, 2, '.', ''),
      number_format($paid_raw, 2, '.', ''),
      number_format($pending, 2, '.', ''),
      $r['ref_no'] ?? '',
      $r['title'] ?? '',
      $r['notes'] ?? '',
    ]);
  }

  fclose($out);
  exit;
}

// ------------------------------
// XLSX export (PhpSpreadsheet)
// ------------------------------
$autoload = __DIR__ . '/../../../vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Transactions');

$title  = 'Transaction Report';
$period = "Period: {$date_from} to {$date_to}";

$sheet->setCellValue('A1', $title);
$sheet->mergeCells('A1:P1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A2', $period);
$sheet->mergeCells('A2:P2');
$sheet->getStyle('A2')->getFont()->setSize(11);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Summary block
$sheet->setCellValue('A4', 'SUMMARY (After contra)');
$sheet->mergeCells('A4:D4');
$sheet->getStyle('A4')->getFont()->setBold(true);

$sumRows = [
  ['Total IN', $total_in_normal],
  ['Total OUT', $total_out_normal],
  ['Net', $net_normal],
  ['Pending (invoice)', $pending_total],
  ['Return balance', $return_balance],
  ['Bonus', $bonus_total],
  ['Summary IN', $summary_in],
  ['Summary OUT', $summary_out],
  ['Summary Net', $summary_net],
];

$r0 = 5;
foreach ($sumRows as $i => $sr) {
  $rr = $r0 + $i;
  $sheet->setCellValue("A{$rr}", $sr[0]);
  $sheet->setCellValue("B{$rr}", (float)$sr[1]);
  $sheet->getStyle("B{$rr}")->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
}
$sheet->getStyle("A5:A".($r0+count($sumRows)-1))->getFont()->setBold(true);

// Header row
$headerRow = $r0 + count($sumRows) + 2; // 留一行空格
$headers = [
  'A' => 'Date',
  'B' => 'Customer Code',
  'C' => 'Customer Name',
  'D' => 'Type',
  'E' => 'Kind',
  'F' => 'Contra',
  'G' => 'Method (Bank)',
  'H' => 'Status',
  'I' => 'Currency',
  'J' => 'Amount (signed)',
  'K' => 'Invoice Total',
  'L' => 'Paid Raw',
  'M' => 'Pending',
  'N' => 'Ref No',
  'O' => 'Title',
  'P' => 'Notes',
];

foreach ($headers as $col => $text) {
  $sheet->setCellValue($col.$headerRow, $text);
}
$sheet->getStyle("A{$headerRow}:P{$headerRow}")->getFont()->setBold(true);
$sheet->getStyle("A{$headerRow}:P{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A{$headerRow}:P{$headerRow}")
  ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');

// Data rows
$rowIndex = $headerRow + 1;

foreach ($rows as $r) {
  $tid = (int)$r['id'];

  $date = $r['txn_effective_date'] ?? ($r['txn_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10));

  $txnType = (string)($r['txn_type'] ?? '');
  $isOut = ($txnType === 'OUT');
  $isContra = ((int)($r['is_contra'] ?? 0) === 1);

  $inKind = strtoupper((string)($r['in_kind'] ?? ''));
  $outKind = strtoupper((string)($r['out_kind'] ?? ''));
  $kind = $txnType === 'IN' ? ($inKind ?: 'IN') : ($outKind ?: 'OUT');

  $amount = (float)($r['amount'] ?? 0);
  $signedAmount = $isOut ? -$amount : $amount;

  $txnCurrency = (string)($r['currency'] ?? 'MYR');
  $orderCurrency = (string)($r['order_currency'] ?? '');
  $invoiceTotal = 0.0;

  if ($txnType === 'IN' && strtoupper((string)($r['in_kind'] ?? 'INVOICE')) === 'INVOICE') {
    $invoiceTotal = (float)($r['order_total'] ?? 0);
    $displayCurrency = $orderCurrency !== '' ? $orderCurrency : $txnCurrency;
  } else {
    $displayCurrency = $txnCurrency;
  }

  $paid_raw = (float)($paidRawByTxn[$tid] ?? 0);
  $pending = 0.0;
  if ($txnType === 'IN' && strtoupper((string)($r['in_kind'] ?? 'INVOICE')) === 'INVOICE' && !$isContra && ($r['status'] ?? '') !== 'CONFIRMED') {
    $pending = max(0.0, $invoiceTotal - $paid_raw);
  }

  // method label
  $methodLabel = '-';
  $bankLabels = [];

  if ($txnType === 'IN' && strtoupper((string)($r['in_kind'] ?? 'INVOICE')) === 'INVOICE') {
    if (!empty($bankIdsByTxn[$tid])) {
      foreach (array_keys($bankIdsByTxn[$tid]) as $bid) {
        if (isset($bankAccMap[(int)$bid])) $bankLabels[] = bank_label($bankAccMap[(int)$bid]);
      }
    }
  } elseif ($txnType === 'OUT') {
    $outBankId = (int)($r['bank_account_id'] ?? 0);
    if ($outBankId > 0 && isset($bankAccMap[$outBankId])) $bankLabels[] = bank_label($bankAccMap[$outBankId]);
  }

  $bankLabels = array_values(array_unique(array_filter($bankLabels, 'strlen')));
  if ($bankLabels) $methodLabel = implode(' / ', $bankLabels);
  else {
    $m = strtoupper((string)($r['method'] ?? ''));
    $methodLabel = $m !== '' ? $m : '-';
  }

  $sheet->setCellValue("A{$rowIndex}", $date);
  $sheet->setCellValue("B{$rowIndex}", $r['customer_code'] ?? '');
  $sheet->setCellValue("C{$rowIndex}", $r['customer_name'] ?? '');
  $sheet->setCellValue("D{$rowIndex}", $txnType);
  $sheet->setCellValue("E{$rowIndex}", $kind);
  $sheet->setCellValue("F{$rowIndex}", $isContra ? 'YES' : 'NO');
  $sheet->setCellValue("G{$rowIndex}", $methodLabel);
  $sheet->setCellValue("H{$rowIndex}", $r['status'] ?? '');
  $sheet->setCellValue("I{$rowIndex}", $displayCurrency);
  $sheet->setCellValue("J{$rowIndex}", (float)$signedAmount);
  $sheet->setCellValue("K{$rowIndex}", (float)$invoiceTotal);
  $sheet->setCellValue("L{$rowIndex}", (float)$paid_raw);
  $sheet->setCellValue("M{$rowIndex}", (float)$pending);
  $sheet->setCellValue("N{$rowIndex}", $r['ref_no'] ?? '');
  $sheet->setCellValue("O{$rowIndex}", $r['title'] ?? '');
  $sheet->setCellValue("P{$rowIndex}", $r['notes'] ?? '');

  $rowIndex++;
}

// number formats
$lastDataRow = $rowIndex - 1;
$sheet->getStyle("J".($headerRow+1).":M{$lastDataRow}")
  ->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');

// borders
$sheet->getStyle("A{$headerRow}:P{$lastDataRow}")
  ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// autosize
foreach (range('A','P') as $col) {
  $sheet->getColumnDimension($col)->setAutoSize(true);
}

// freeze header
$sheet->freezePane('A'.($headerRow+1));

// output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filenameBase.'.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
