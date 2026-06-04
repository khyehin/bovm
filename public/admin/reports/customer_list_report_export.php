<?php
// public/admin/reports/customer_list_report_export.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

$autoload = __DIR__ . '/../../../vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

require_login();
require_admin();
if (function_exists('require_perm')) {
  require_perm('REPORT.V');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Date filters
$today = date('Y-m-d');
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';

if ($date_from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
  $date_from = date('Y-m-01');
}
if ($date_to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
  $date_to = date('Y-m-t');
}

$dateExpr = "COALESCE(t.txn_date, DATE(t.created_at))";

// Other filters
$q          = trim($_GET['q'] ?? '');
$onlyActive = isset($_GET['only_active']) && $_GET['only_active'] === '1';

// customers
$sqlCust = "SELECT c.id, c.code, c.name, c.is_active FROM customers c";
$whereCust = [];
$paramsCust = [];

if ($q !== '') {
  $whereCust[] = "(c.name LIKE :q OR c.code LIKE :q)";
  $paramsCust[':q'] = '%'.$q.'%';
}
if ($onlyActive) {
  $whereCust[] = "c.is_active = 1";
}
if ($whereCust) {
  $sqlCust .= " WHERE ".implode(' AND ', $whereCust);
}
$sqlCust .= " ORDER BY c.code ASC, c.name ASC";

$st = $pdo->prepare($sqlCust);
$st->execute($paramsCust);
$customers = $st->fetchAll();

$rows = [];

// totals
$tot_in_normal = 0.0;
$tot_out_normal = 0.0;
$tot_net_normal = 0.0;
$tot_pending = 0.0;

$tot_bonus = 0.0;
$tot_return_balance = 0.0;

$tot_sum_net = 0.0;

if ($customers) {
  $custIds = array_map(fn($r) => (int)$r['id'], $customers);
  $inClause = implode(',', array_fill(0, count($custIds), '?'));

  // sums
  $sumSql = "
    SELECT
      t.customer_id,

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
                       AND (t.title LIKE '%Repayment%' OR t.title LIKE '%repayment%')
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
    WHERE t.customer_id IN ($inClause)
      AND {$dateExpr} BETWEEN ? AND ?
    GROUP BY t.customer_id
  ";
  $paramsSum = array_merge($custIds, [$date_from, $date_to]);
  $st = $pdo->prepare($sumSql);
  $st->execute($paramsSum);

  $sumMap = [];
  while ($r = $st->fetch()) {
    $sumMap[(int)$r['customer_id']] = $r;
  }

  // pending
  $pendingSql = "
    SELECT
      t.customer_id,
      COALESCE(SUM(
        GREATEST(
          COALESCE(t.order_total,0) - COALESCE(paid.paid_total,0),
          0
        )
      ),0) AS pending_total
    FROM customer_txn t
    LEFT JOIN (
      SELECT customer_txn_id, SUM(amount) AS paid_total
      FROM customer_txn_payments
      GROUP BY customer_txn_id
    ) paid ON paid.customer_txn_id = t.id
    WHERE t.customer_id IN ($inClause)
      AND t.txn_type = 'IN'
      AND (t.is_contra IS NULL OR t.is_contra = 0)
      AND (t.status IS NULL OR t.status <> 'CONFIRMED')
      AND (UPPER(COALESCE(t.in_kind,'')) = '' OR UPPER(COALESCE(t.in_kind,'')) = 'INVOICE')
      AND {$dateExpr} BETWEEN ? AND ?
    GROUP BY t.customer_id
  ";
  $paramsPend = array_merge($custIds, [$date_from, $date_to]);
  $st = $pdo->prepare($pendingSql);
  $st->execute($paramsPend);

  $pendingMap = [];
  while ($r = $st->fetch()) {
    $pendingMap[(int)$r['customer_id']] = (float)($r['pending_total'] ?? 0);
  }

  foreach ($customers as $c) {
    $cid = (int)$c['id'];
    $sum = $sumMap[$cid] ?? [
      'total_in_normal'  => 0,
      'total_out_normal' => 0,
      'bonus_total'      => 0,
      'repay_total'      => 0,
      'loan_total'       => 0,
    ];

    $in_normal  = (float)($sum['total_in_normal'] ?? 0);
    $out_normal = (float)($sum['total_out_normal'] ?? 0);
    $bonus      = (float)($sum['bonus_total'] ?? 0);
    $repay      = (float)($sum['repay_total'] ?? 0);
    $loan       = (float)($sum['loan_total'] ?? 0);

    $net_normal = $in_normal - $out_normal;
    $return_balance = $loan - $repay;

    $summary_in  = $in_normal + $bonus + $repay;
    $summary_out = $out_normal + $loan;
    $summary_net = $summary_in - $summary_out;

    $pending = (float)($pendingMap[$cid] ?? 0);

    $tot_in_normal  += $in_normal;
    $tot_out_normal += $out_normal;
    $tot_net_normal += $net_normal;
    $tot_pending    += $pending;

    $tot_bonus          += $bonus;
    $tot_return_balance += $return_balance;

    $tot_sum_net += $summary_net;

    $rows[] = [
      'id' => $cid,
      'code' => $c['code'] ?? '',
      'name' => $c['name'] ?? '',
      'is_active' => isset($c['is_active']) ? (int)$c['is_active'] : 1,

      'in_normal' => $in_normal,
      'out_normal' => $out_normal,
      'net_normal' => $net_normal,
      'pending' => $pending,

      'return_balance' => $return_balance,
      'bonus' => $bonus,

      'summary_net' => $summary_net,
    ];
  }
}

// Audit
if (function_exists('audit_log')) {
  audit_log(
    $pdo,
    'REPORT.CUSTOMER.EXPORT',
    [
      'description' => 'Export customer list report',
      'q'           => $q !== '' ? $q : null,
      'only_active' => $onlyActive ? 1 : 0,
      'date_from'   => $date_from,
      'date_to'     => $date_to,
    ],
    'report_customer',
    null
  );
}

// Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Customer List');

$sheet->setCellValue('A1', 'Customer List Report');
$sheet->mergeCells('A1:J1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

$sheet->setCellValue('A2', "Period: {$date_from} to {$date_to}");
$sheet->mergeCells('A2:J2');
$sheet->getStyle('A2')->getFont()->setSize(11);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

$headerRow = 4;
$sheet->setCellValue("A{$headerRow}", 'ID');
$sheet->setCellValue("B{$headerRow}", 'Code');
$sheet->setCellValue("C{$headerRow}", 'Customer Name');

$sheet->setCellValue("D{$headerRow}", 'Total IN');
$sheet->setCellValue("E{$headerRow}", 'Total OUT');
$sheet->setCellValue("F{$headerRow}", 'Net');

$sheet->setCellValue("G{$headerRow}", 'Pending');
$sheet->setCellValue("H{$headerRow}", 'Return (Loan - Repayment)');
$sheet->setCellValue("I{$headerRow}", 'Bonus');
$sheet->setCellValue("J{$headerRow}", 'Summary Net');

$sheet->getStyle("A{$headerRow}:J{$headerRow}")->getFont()->setBold(true);
$sheet->getStyle("A{$headerRow}:J{$headerRow}")
  ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A{$headerRow}:J{$headerRow}")
  ->getFill()->setFillType(Fill::FILL_SOLID)
  ->getStartColor()->setARGB('FFE5E7EB');

$dataStartRow = $headerRow + 1;
$currentRow = $dataStartRow;

if ($rows) {
  foreach ($rows as $r) {
    $sheet->setCellValue("A{$currentRow}", $r['id']);
    $sheet->setCellValue("B{$currentRow}", $r['code']);
    $sheet->setCellValue("C{$currentRow}", $r['name']);

    $sheet->setCellValue("D{$currentRow}", $r['in_normal']);
    $sheet->setCellValue("E{$currentRow}", $r['out_normal']);
    $sheet->setCellValue("F{$currentRow}", $r['net_normal']);

    $sheet->setCellValue("G{$currentRow}", $r['pending']);
    $sheet->setCellValue("H{$currentRow}", $r['return_balance']);
    $sheet->setCellValue("I{$currentRow}", $r['bonus']);
    $sheet->setCellValue("J{$currentRow}", $r['summary_net']);

    $currentRow++;
  }

  // TOTAL
  $sheet->setCellValue("A{$currentRow}", 'TOTAL');
  $sheet->mergeCells("A{$currentRow}:C{$currentRow}");

  $sheet->setCellValue("D{$currentRow}", $tot_in_normal);
  $sheet->setCellValue("E{$currentRow}", $tot_out_normal);
  $sheet->setCellValue("F{$currentRow}", $tot_net_normal);

  $sheet->setCellValue("G{$currentRow}", $tot_pending);
  $sheet->setCellValue("H{$currentRow}", $tot_return_balance);
  $sheet->setCellValue("I{$currentRow}", $tot_bonus);
  $sheet->setCellValue("J{$currentRow}", $tot_sum_net);

  $sheet->getStyle("A{$currentRow}:J{$currentRow}")->getFont()->setBold(true);
} else {
  $sheet->setCellValue("A{$dataStartRow}", 'No customers for this filter.');
  $sheet->mergeCells("A{$dataStartRow}:J{$dataStartRow}");
}

// number formats
$sheet->getStyle("D{$dataStartRow}:E{$currentRow}")
  ->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle("G{$dataStartRow}:I{$currentRow}")
  ->getNumberFormat()->setFormatCode('#,##0.00');

$sheet->getStyle("F{$dataStartRow}:F{$currentRow}")
  ->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
$sheet->getStyle("H{$dataStartRow}:H{$currentRow}")
  ->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
$sheet->getStyle("J{$dataStartRow}:J{$currentRow}")
  ->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');

// borders
$lastRowForBorder = max($currentRow, $dataStartRow);
$sheet->getStyle("A{$headerRow}:J{$lastRowForBorder}")
  ->getBorders()->getAllBorders()
  ->setBorderStyle(Border::BORDER_THIN);

// autosize
foreach (range('A','J') as $col) {
  $sheet->getColumnDimension($col)->setAutoSize(true);
}

$fileName = 'customer_list_report_' . date('Ymd_His') . '.xlsx';
if (ob_get_length()) ob_end_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$fileName.'"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
