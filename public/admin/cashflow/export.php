<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('CASHFLOW.EXPORT');

$pdo = get_pdo();
$companyId = max(0, (int)($_GET['company_id'] ?? 0));
$from = trim((string)($_GET['date_from'] ?? ''));
$to = trim((string)($_GET['date_to'] ?? ''));
$remark = trim((string)($_GET['remark'] ?? ''));
$all = ($_GET['date_all'] ?? '0') === '1';

$where = [$companyId === 0 ? 'e.company_id IS NULL' : 'e.company_id = :company_id'];
$params = $companyId === 0 ? [] : [':company_id' => $companyId];
if (!$all && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'e.entry_date >= :date_from'; $params[':date_from'] = $from; }
if (!$all && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'e.entry_date <= :date_to'; $params[':date_to'] = $to; }
if ($remark !== '') { $where[] = 'e.remark LIKE :remark'; $params[':remark'] = '%' . $remark . '%'; }

$columns = $pdo->query('SELECT id, name FROM cashflow_extra_columns ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
$sql = 'SELECT e.* FROM cashflow_entries e WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(e.display_order, 2147483647), e.entry_date, e.id';
$st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
$extras = [];
if ($rows) {
    $ids = array_map('intval', array_column($rows, 'id'));
    $q = $pdo->query('SELECT cashflow_entry_id, cashflow_extra_column_id, value FROM cashflow_entry_extra_values WHERE cashflow_entry_id IN (' . implode(',', $ids) . ')');
    foreach ($q as $v) $extras[(int)$v['cashflow_entry_id']][(int)$v['cashflow_extra_column_id']] = $v['value'];
}

if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) exit('PhpSpreadsheet is not installed.');
$book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $book->getActiveSheet();
$headers = ['Date', 'Deposit', 'Withdrawals', 'AFFIN', 'Total', 'Xe USDT'];
foreach ($columns as $c) $headers[] = $c['name'];
$headers[] = 'Remark';
$sheet->fromArray($headers, null, 'A1');
$line = 2;
foreach ($rows as $r) {
    $data = [$r['entry_date'], $r['deposit'], $r['withdrawal'], $r['affin'], $r['total'], $r['xe_usdt']];
    foreach ($columns as $c) $data[] = $extras[(int)$r['id']][(int)$c['id']] ?? 0;
    $data[] = $r['remark'];
    $sheet->fromArray($data, null, 'A' . $line++);
}
$lastColumn = $sheet->getHighestColumn();
$sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
$lastIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastColumn);
for ($i = 1; $i <= $lastIndex; $i++) {
    $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
}
$filename = 'cashflow_' . ($companyId ?: 'master') . '_' . date('Y-m-d_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
audit_log($pdo, 'CASHFLOW.EXPORT', ['company_id' => $companyId, 'rows' => count($rows)], 'cashflow', null);
exit;
