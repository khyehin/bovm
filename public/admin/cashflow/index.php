<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('CASHFLOW.V');

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$user = current_user();
$canEdit = can('CASHFLOW.E');
$canDelete = can('CASHFLOW.D');
$canExport = can('CASHFLOW.EXPORT');

if (empty($_SESSION['cashflow_csrf'])) $_SESSION['cashflow_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['cashflow_csrf'];

function cf_money($value, bool $withdrawal = false, bool $showZero = false): string
{
    if ($value === null || (!$showZero && abs((float)$value) < 0.00001)) return '';
    $n = (float)$value;
    if ($withdrawal || $n < 0) return '<span class="cf-negative">(' . number_format(abs($n), 2) . ')</span>';
    return number_format($n, 2);
}
function cf_date(string $value): bool { $d = DateTime::createFromFormat('Y-m-d', $value); return $d && $d->format('Y-m-d') === $value; }
function cf_redirect(array $query = []): void
{
    header('Location: ' . url('admin/cashflow/index.php') . ($query ? '?' . http_build_query($query) : '')); exit;
}

$companyId = max(0, (int)($_REQUEST['company_id'] ?? 0));
$dateAll = ($_REQUEST['date_all'] ?? '0') === '1';
$dateFrom = trim((string)($_REQUEST['date_from'] ?? ''));
$dateTo = trim((string)($_REQUEST['date_to'] ?? ''));
$remark = trim((string)($_REQUEST['remark'] ?? ''));
if (!$dateAll && !cf_date($dateFrom) && !cf_date($dateTo)) {
    $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-t');
}
$back = ['company_id' => $companyId, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'date_all' => $dateAll ? '1' : '0', 'remark' => $remark];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Invalid form token.'); }
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!$canEdit && $action !== 'delete_row') throw new RuntimeException('You do not have edit permission.');
        if ($action === 'add_column') {
            $name = trim((string)($_POST['column_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 64) throw new RuntimeException('Column name is required (maximum 64 characters).');
            $st = $pdo->prepare('INSERT INTO cashflow_extra_columns (name, sort_order) VALUES (:name, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 n FROM cashflow_extra_columns) x))');
            $st->execute([':name' => $name]);
            audit_log($pdo, 'CASHFLOW.COLUMN.CREATE', ['name' => $name], 'cashflow_extra_columns', (int)$pdo->lastInsertId());
            $_SESSION['cf_success'] = 'Column added.';
        } elseif ($action === 'delete_column') {
            if (!$canDelete) throw new RuntimeException('You do not have delete permission.');
            $id = (int)($_POST['column_id'] ?? 0);
            $st = $pdo->prepare('DELETE FROM cashflow_extra_columns WHERE id = :id'); $st->execute([':id' => $id]);
            audit_log($pdo, 'CASHFLOW.COLUMN.DELETE', [], 'cashflow_extra_columns', $id);
            $_SESSION['cf_success'] = 'Column removed.';
        } elseif ($action === 'hide_fixed_column') {
            if (!$canDelete) throw new RuntimeException('You do not have delete permission.');
            $key = (string)($_POST['column_key'] ?? '');
            if (!in_array($key, ['affin', 'xe_usdt'], true)) throw new RuntimeException('This column cannot be removed.');
            $st = $pdo->prepare('SELECT column_order FROM cashflow_column_orders WHERE company_id=:cid');
            $st->execute([':cid'=>$companyId]);
            $order = json_decode((string)$st->fetchColumn(), true);
            if (!is_array($order)) $order = ['date','deposit','withdrawal','affin','total','xe_usdt','remark'];
            $order = array_values(array_filter($order, static fn($v) => $v !== $key));
            $st = $pdo->prepare('INSERT INTO cashflow_column_orders (company_id,column_order) VALUES (:cid,:o) ON DUPLICATE KEY UPDATE column_order=VALUES(column_order)');
            $st->execute([':cid'=>$companyId, ':o'=>json_encode($order)]);
            audit_log($pdo, 'CASHFLOW.COLUMN.HIDE', ['column_key'=>$key], 'cashflow', null);
            $_SESSION['cf_success'] = 'Column removed.';
        } elseif ($action === 'delete_row') {
            if (!$canDelete) throw new RuntimeException('You do not have delete permission.');
            $id = (int)($_POST['entry_id'] ?? 0);
            $scope = $companyId === 0 ? 'company_id IS NULL' : 'company_id = :cid';
            $st = $pdo->prepare("DELETE FROM cashflow_entries WHERE id = :id AND $scope");
            $args = [':id' => $id]; if ($companyId) $args[':cid'] = $companyId; $st->execute($args);
            audit_log($pdo, 'CASHFLOW.ROW.DELETE', ['company_id' => $companyId], 'cashflow_entries', $id);
            $_SESSION['cf_success'] = 'Cashflow entry deleted.';
        } elseif ($action === 'save_rows') {
            $pdo->beginTransaction();
            $existing = is_array($_POST['entries'] ?? null) ? $_POST['entries'] : [];
            $newRows = is_array($_POST['new_rows'] ?? null) ? $_POST['new_rows'] : [];
            $tokens = array_filter(array_map('trim', explode(',', (string)($_POST['row_order'] ?? ''))));
            $orders = []; foreach (array_values($tokens) as $i => $token) $orders[$token] = $i + 1;
            $scope = $companyId === 0 ? 'company_id IS NULL' : 'company_id = :cid';
            $update = $pdo->prepare("UPDATE cashflow_entries SET entry_date=:dt, deposit=:dep, withdrawal=:wd, affin=:affin, total=:total, xe_usdt=:xe, remark=:remark, display_order=:ord WHERE id=:id AND $scope");
            $upExtra = $pdo->prepare('INSERT INTO cashflow_entry_extra_values (cashflow_entry_id,cashflow_extra_column_id,value) VALUES (:eid,:cid,:v) ON DUPLICATE KEY UPDATE value=VALUES(value)');
            $saved = 0;
            foreach ($existing as $id => $row) {
                $id = (int)$id; $dt = (string)($row['entry_date'] ?? ''); if (!$id || !cf_date($dt)) continue;
                $dep = ($row['deposit'] ?? '') === '' ? null : (float)$row['deposit']; $wd = ($row['withdrawal'] ?? '') === '' ? null : abs((float)$row['withdrawal']);
                $total = ($row['total'] ?? '') === '' ? (float)($dep ?? 0) - (float)($wd ?? 0) : (float)$row['total'];
                $args = [':dt'=>$dt,':dep'=>$dep,':wd'=>$wd,':affin'=>(float)($row['affin']??0),':total'=>$total,':xe'=>(float)($row['xe_usdt']??0),':remark'=>trim((string)($row['remark']??'')),':ord'=>$orders['e:'.$id]??null,':id'=>$id];
                if ($companyId) $args[':cid']=$companyId; $update->execute($args);
                if ($update->rowCount() || true) { foreach ((array)($row['extra']??[]) as $cid=>$v) $upExtra->execute([':eid'=>$id,':cid'=>(int)$cid,':v'=>(float)$v]); $saved++; }
            }
            $insert = $pdo->prepare('INSERT INTO cashflow_entries (company_id,user_id,display_order,entry_date,deposit,withdrawal,affin,total,xe_usdt,remark) VALUES (:company,:uid,:ord,:dt,:dep,:wd,:affin,:total,:xe,:remark)');
            foreach ($newRows as $idx => $row) {
                $dt=(string)($row['entry_date']??''); if (!cf_date($dt)) continue;
                $dep=($row['deposit']??'')===''?null:(float)$row['deposit']; $wd=($row['withdrawal']??'')===''?null:abs((float)$row['withdrawal']);
                $total=($row['total']??'')===''?(float)($dep??0)-(float)($wd??0):(float)$row['total'];
                $insert->execute([':company'=>$companyId?:null,':uid'=>(int)$user['id'],':ord'=>$orders['n:'.$idx]??null,':dt'=>$dt,':dep'=>$dep,':wd'=>$wd,':affin'=>(float)($row['affin']??0),':total'=>$total,':xe'=>(float)($row['xe_usdt']??0),':remark'=>trim((string)($row['remark']??''))]);
                $eid=(int)$pdo->lastInsertId(); foreach ((array)($row['extra']??[]) as $cid=>$v) $upExtra->execute([':eid'=>$eid,':cid'=>(int)$cid,':v'=>(float)$v]); $saved++;
            }
            $columnOrder = json_decode((string)($_POST['column_order'] ?? ''), true);
            if (is_array($columnOrder)) {
                $st=$pdo->prepare('INSERT INTO cashflow_column_orders (company_id,column_order) VALUES (:cid,:o) ON DUPLICATE KEY UPDATE column_order=VALUES(column_order)');
                $st->execute([':cid'=>$companyId,':o'=>json_encode(array_values($columnOrder))]);
            }
            $pdo->commit(); audit_log($pdo, 'CASHFLOW.ROWS.SAVE', ['company_id'=>$companyId,'rows'=>$saved], 'cashflow', null); $_SESSION['cf_success']='Saved.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); $_SESSION['cf_error']=$e->getMessage();
    }
    cf_redirect($back);
}

$companies = $pdo->query('SELECT id,name FROM payer_companies WHERE is_active=1 ORDER BY name,id')->fetchAll();
if ($companyId > 0 && !in_array($companyId, array_map('intval', array_column($companies, 'id')), true)) $companyId = 0;
$columns = $pdo->query('SELECT id,name FROM cashflow_extra_columns ORDER BY sort_order,id')->fetchAll();
$where=[$companyId===0?'e.company_id IS NULL':'e.company_id=:cid']; $params=$companyId===0?[]:[':cid'=>$companyId];
if (!$dateAll && cf_date($dateFrom)) {$where[]='e.entry_date>=:df';$params[':df']=$dateFrom;} if (!$dateAll && cf_date($dateTo)) {$where[]='e.entry_date<=:dt';$params[':dt']=$dateTo;}
if ($remark!=='') {$where[]='e.remark LIKE :remark';$params[':remark']='%'.$remark.'%';}
$st=$pdo->prepare('SELECT e.* FROM cashflow_entries e WHERE '.implode(' AND ',$where).' ORDER BY COALESCE(e.display_order,2147483647),e.entry_date,e.id');$st->execute($params);$rows=$st->fetchAll();
$extras=[]; if($rows){$ids=array_map('intval',array_column($rows,'id'));foreach($pdo->query('SELECT * FROM cashflow_entry_extra_values WHERE cashflow_entry_id IN ('.implode(',',$ids).')') as $v)$extras[(int)$v['cashflow_entry_id']][(int)$v['cashflow_extra_column_id']]=$v['value'];}
$beforeWhere=[$companyId===0?'company_id IS NULL':'company_id=:cid'];$beforeParams=$companyId===0?[]:[':cid'=>$companyId];$bfDate=cf_date($dateFrom)?$dateFrom:($rows[0]['entry_date']??date('Y-m-01'));$beforeWhere[]='entry_date<:bf';$beforeParams[':bf']=$bfDate;
$st=$pdo->prepare('SELECT COALESCE(SUM(total),0) total,COALESCE(SUM(affin),0) affin,COALESCE(SUM(xe_usdt),0) xe FROM cashflow_entries WHERE '.implode(' AND ',$beforeWhere));$st->execute($beforeParams);$bf=$st->fetch();
$bfDisplayDate=date('Y-m-01',strtotime($bfDate));
$bfLabel=date('MY',strtotime($bfDisplayDate.' -1 month'));
$bfExtras=[];if($columns){$sql='SELECT v.cashflow_extra_column_id,SUM(v.value) value FROM cashflow_entry_extra_values v JOIN cashflow_entries e ON e.id=v.cashflow_entry_id WHERE '.str_replace('company_id','e.company_id',implode(' AND ',$beforeWhere)).' GROUP BY v.cashflow_extra_column_id';$st=$pdo->prepare($sql);$st->execute($beforeParams);foreach($st as $v)$bfExtras[(int)$v['cashflow_extra_column_id']]=$v['value'];}
$defaultOrder=['date','deposit','withdrawal','affin','total','xe_usdt'];foreach($columns as $c)$defaultOrder[]='extra:'.$c['id'];$defaultOrder[]='remark';
$st=$pdo->prepare('SELECT column_order FROM cashflow_column_orders WHERE company_id=:cid');$st->execute([':cid'=>$companyId]);$savedOrder=json_decode((string)$st->fetchColumn(),true);
if (is_array($savedOrder)) {
    $columnOrder=array_values(array_unique(array_intersect($savedOrder,$defaultOrder)));
    foreach (['date','deposit','withdrawal','total','remark'] as $requiredKey) {
        if (!in_array($requiredKey,$columnOrder,true)) $columnOrder[]=$requiredKey;
    }
    $remarkPos=array_search('remark',$columnOrder,true);
    foreach($columns as $c){$extraKey='extra:'.$c['id'];if(!in_array($extraKey,$columnOrder,true)){array_splice($columnOrder,$remarkPos,0,[$extraKey]);$remarkPos++;}}
} else {
    $columnOrder=$defaultOrder;
}
$success=$_SESSION['cf_success']??'';$error=$_SESSION['cf_error']??'';unset($_SESSION['cf_success'],$_SESSION['cf_error']);
$page_title='Cashflow'; include __DIR__.'/../include/header.php';
?>
<style>
.cf-page{width:100%;max-width:1280px;min-width:720px;margin:0 auto!important;padding:0!important;border-radius:0!important;box-shadow:none!important;background:transparent!important}.cf-toolbar,.cf-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end}.cf-toolbar{justify-content:space-between;margin-bottom:0}.cf-toolbar .page-title{font-size:20px;line-height:1.25;margin:6px 0 4px}.cf-toolbar .form-page-subtitle{font-size:13px}.cf-actions{display:flex;flex-wrap:nowrap;gap:12px;align-items:center}.cf-mode-form{display:flex;align-items:center;gap:10px}.cf-mode-label{font-size:13px;color:#4b5563}.cf-top-select{width:192px!important;height:40px}.cf-column-form{display:flex;gap:8px}.cf-column-input{width:128px!important;height:40px}.cf-actions .btn{height:40px;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;padding:0 14px}.cf-filters{border-top:1px solid #e5e7eb;margin-top:18px;padding-top:28px;margin-bottom:38px}.cf-filter-field{width:260px;max-width:260px}.cf-filter-field .form-group{display:flex;flex-direction:column;gap:5px;margin:0}.cf-filter-field .field-label,.cf-field label{font-size:12px;color:#6b7280}.cf-filter-field .drp-display-input,.cf-filter-control{height:34px!important;min-height:34px!important;border-radius:5px!important;padding:0 10px!important;font-size:13px!important}.cf-field{display:flex;flex-direction:column;gap:5px;width:260px}.cf-input{width:100%;min-width:92px;border:1px solid #d1d5db;border-radius:4px;padding:4px 6px;font:inherit}.cf-table-wrap{overflow-x:auto;margin-top:0;border:1px solid #e1e5eb;border-radius:10px}.cf-table{min-width:900px;border-collapse:separate;border-spacing:0;width:100%;table-layout:fixed}.cf-table th,.cf-table td{border:0;border-right:1px solid #e1e5eb;border-bottom:1px solid #e1e5eb;padding:11px 14px;text-align:right;white-space:nowrap;height:46px}.cf-table tr>*:last-child{border-right:0}.cf-table tfoot tr:last-child td{border-bottom:0}.cf-table th{background:#f9fafb;color:#111827;font-size:13px;font-weight:600}.cf-table th:first-child,.cf-table td:first-child,.cf-left{text-align:left!important}.cf-table td{font-size:14px}.cf-table .cf-input{max-width:128px;height:34px}.cf-negative{color:#ef2929}.cf-edit-cell{display:none}.cf-edit-mode .cf-edit-cell{display:block}.cf-edit-mode .cf-view-cell{display:none}.cf-delete-col{display:none}.cf-edit-mode .cf-delete-col{display:table-cell}.cf-row{cursor:move}.cf-total-row{font-weight:700;background:#f9fafb}.cf-bf-row{background:#fff;color:#374151}.cf-icon{border:1px solid #ef4444;color:#b91c1c;background:#fff;border-radius:4px;cursor:pointer}.cf-alert{padding:10px 12px;border-radius:6px;margin-bottom:12px}.cf-success{background:#ecfdf5;color:#047857}.cf-error{background:#fef2f2;color:#b91c1c}.cf-save{display:none;margin-top:12px;justify-content:flex-start}.cf-edit-mode .cf-save{display:flex}.cf-head-inner{display:flex;gap:5px;align-items:center;justify-content:flex-end}@media(max-width:900px){.cf-page{min-width:0}.cf-toolbar,.cf-actions,.cf-filters{align-items:stretch;flex-direction:column}.cf-actions>*{width:100%}.cf-top-select,.cf-column-input,.cf-filter-field,.cf-field{width:100%!important;max-width:none}}
/* Keep the Master proportions inside the BOVM card treatment. */
.admin-main{background:#f3f4f6}.cf-page{max-width:1344px;padding:26px 28px 22px!important;border-radius:18px!important;background:#fff!important;box-shadow:0 3px 12px rgba(15,23,42,.06)!important}
.cf-table th .cf-head-inner{justify-content:center}.cf-table th[data-key="remark"] .cf-head-inner{justify-content:flex-start}.cf-table tbody td:first-child{text-align:center!important}.cf-table tbody td.cf-left[colspan]{text-align:left!important}
.admin-main,.admin-main-inner{min-width:0}.cf-page{box-sizing:border-box;width:100%!important;max-width:1344px!important}.cf-table-wrap{box-sizing:border-box;width:100%;max-width:100%}.cf-delete-col-width{display:none}.cf-edit-mode .cf-delete-col-width{display:table-column}
.cf-delete-column,.cf-hide-fixed{display:none!important}.cf-edit-mode .cf-delete-column,.cf-edit-mode .cf-hide-fixed{display:inline-flex!important;align-items:center;justify-content:center;width:18px;height:18px;padding:0}
.cf-toolbar>div:first-child{flex:1 1 300px;min-width:240px}.cf-actions{flex:1 1 700px;min-width:0;max-width:100%;justify-content:flex-end}.cf-column-form{min-width:0;max-width:100%}.cf-column-input{flex:1 1 128px;min-width:100px}
@media(max-width:1550px){.cf-toolbar{align-items:flex-start}.cf-actions{flex-basis:100%;width:100%;justify-content:flex-start;flex-wrap:wrap}}
@media(max-width:1180px){
  .cf-toolbar{align-items:flex-start;gap:18px}.cf-actions{width:100%;flex-wrap:wrap}.cf-filters{padding-top:20px;margin-bottom:28px}.cf-table-wrap{max-width:100%}
}
@media(max-width:768px){
  .admin-main{padding:14px 12px}.cf-page{min-width:0!important;width:100%;padding:18px 14px!important;border-radius:14px!important}
  .cf-toolbar{display:block}.cf-toolbar>div:first-child{margin-bottom:16px}.cf-toolbar .page-title{font-size:18px}
  .cf-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}.cf-mode-form,.cf-column-form{grid-column:1/-1;width:100%}
  .cf-mode-label{min-width:52px}.cf-top-select{width:100%!important;flex:1}.cf-column-input{width:100%!important;flex:1;min-width:0}
  .cf-actions .btn{width:100%;height:38px}.cf-column-form .btn{width:auto;min-width:116px}
  .cf-filters{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;padding-top:18px;margin-bottom:24px}
  .cf-filter-field,.cf-field{grid-column:1/-1;width:100%!important;max-width:none}.cf-filters>.btn{width:100%;justify-content:center}
  .cf-table-wrap{overflow-x:auto;border-radius:8px;-webkit-overflow-scrolling:touch}.cf-table{min-width:900px}.cf-table th,.cf-table td{padding:9px 10px;height:42px}
  .cf-save{position:sticky;left:0}.cf-save .btn{width:100%}
}
@media(max-width:420px){
  .admin-main{padding:10px 8px}.cf-page{padding:16px 10px!important}.cf-actions{grid-template-columns:1fr}.cf-mode-form,.cf-column-form{grid-column:auto}
  .cf-column-form{display:grid;grid-template-columns:1fr}.cf-column-form .btn{width:100%}.cf-filters{grid-template-columns:1fr}.cf-toolbar .form-page-subtitle{line-height:1.45}
}
</style>
<div class="admin-main"><div class="admin-main-inner"><div class="admin-card cf-page" id="cf-page">
<?php if($success):?><div class="cf-alert cf-success"><?=h($success)?></div><?php endif;?><?php if($error):?><div class="cf-alert cf-error"><?=h($error)?></div><?php endif;?>
<div class="cf-toolbar"><div><div class="form-page-eyebrow">CASHFLOW</div><h1 class="page-title"><?= $companyId===0?'Master Cashflow':'Company Cashflow' ?></h1><div class="form-page-subtitle"><?= $companyId===0?'Base currency: MYR. Add row or add column below.':h(array_values(array_filter($companies,fn($c)=>(int)$c['id']===$companyId))[0]['name']??'') ?></div></div>
<div class="cf-actions"><form method="get" class="cf-mode-form"><label class="cf-mode-label"><?=$companyId===0?'Master':'Company'?></label><select name="company_id" class="cf-input cf-top-select" onchange="this.form.submit()"><option value="0">Master Cashflow</option><?php foreach($companies as $c):?><option value="<?=$c['id']?>" <?=$companyId===(int)$c['id']?'selected':''?>><?=h($c['name'])?></option><?php endforeach;?></select></form>
<?php if($canEdit):?><button type="button" id="cf-edit" class="btn btn-light">Edit</button><button type="button" id="cf-add" class="btn btn-primary" style="display:none">+ Add row</button><form method="post" class="cf-column-form"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="add_column"><input type="hidden" name="company_id" value="<?=$companyId?>"><input name="column_name" class="cf-input cf-column-input" maxlength="64" placeholder="Column name" required><button class="btn btn-light">+ Add column</button></form><?php endif;?>
<?php if($canExport):?><a class="btn btn-light" href="<?=h(url('admin/cashflow/export.php?'.http_build_query($back)))?>">Export</a><?php endif;?></div></div>
<form method="get" class="cf-filters"><input type="hidden" name="company_id" value="<?=$companyId?>"><div class="cf-filter-field"><?php $date_from=$dateFrom;$date_to=$dateTo;$date_all=$dateAll?'1':'0';include __DIR__.'/../../include/date_range.php';?></div><div class="cf-field"><label>Remark</label><input name="remark" value="<?=h($remark)?>" class="cf-input cf-filter-control" placeholder="Search by remark..."></div><button class="btn btn-primary cf-filter-control">Apply</button><a class="btn btn-light cf-filter-control" href="<?=h(url('admin/cashflow/index.php?company_id='.$companyId))?>">Reset</a></form>
<form method="post" id="cf-form"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="save_rows"><input type="hidden" name="company_id" value="<?=$companyId?>"><input type="hidden" name="date_from" value="<?=h($dateFrom)?>"><input type="hidden" name="date_to" value="<?=h($dateTo)?>"><input type="hidden" name="date_all" value="<?=$dateAll?'1':'0'?>"><input type="hidden" name="remark" value="<?=h($remark)?>"><input type="hidden" name="row_order" id="cf-row-order"><input type="hidden" name="column_order" id="cf-column-order">
<div class="cf-table-wrap"><table class="cf-table"><colgroup><?php foreach($columnOrder as $key):?><col style="width:<?= $key==='date'?'144px':($key==='deposit'?'130px':($key==='withdrawal'?'145px':($key==='remark'?'292px':'142px'))) ?>"><?php endforeach;?><col class="cf-delete-col-width" style="width:46px"></colgroup><thead><tr id="cf-header">
<?php foreach($columnOrder as $key): $label=['date'=>'Date','deposit'=>'Deposit','withdrawal'=>'Withdrawals','affin'=>'AFFIN','total'=>'Total','xe_usdt'=>'Xe USDT','remark'=>'Remark'][$key]??'';if(str_starts_with($key,'extra:')){foreach($columns as $c)if('extra:'.$c['id']===$key)$label=$c['name'];}?><th draggable="true" data-key="<?=h($key)?>"><div class="cf-head-inner"><?=h($label)?><?php if(str_starts_with($key,'extra:')&&$canDelete):?><button type="button" class="cf-icon cf-delete-column" data-id="<?=substr($key,6)?>">×</button><?php elseif(in_array($key,['affin','xe_usdt'],true)&&$canDelete):?><button type="button" class="cf-icon cf-hide-fixed" data-key="<?=h($key)?>">×</button><?php endif;?></div></th><?php endforeach;?><th class="cf-delete-col">Delete</th></tr></thead><tbody id="cf-body">
<?php if(array_sum(array_map('floatval',$bf))+array_sum(array_map('floatval',$bfExtras))!=0):?><tr class="cf-bf-row"><?php foreach($columnOrder as $key):?><td class="<?=$key==='remark'?'cf-left':''?>"><?php if($key==='date'):?><?=h($bfDisplayDate)?><?php elseif($key==='affin'):?><?=cf_money($bf['affin'])?><?php elseif($key==='total'):?><?=cf_money($bf['total'])?><?php elseif($key==='xe_usdt'):?><?=cf_money($bf['xe'])?><?php elseif($key==='remark'):?><?=h('Balance bring forward '.$bfLabel)?><?php elseif(str_starts_with($key,'extra:')):?><?=cf_money($bfExtras[(int)substr($key,6)]??0)?><?php endif;?></td><?php endforeach;?><td class="cf-delete-col"></td></tr><?php endif;?>
<?php if(!$rows):?><tr id="cf-empty-row"><td colspan="<?=count($columnOrder)+1?>" class="cf-left" style="padding:2rem;color:#6b7280">No cashflow entries. Click Edit, then + Add row to add data.</td></tr><?php endif;?>
<?php foreach($rows as $r):?><tr class="cf-row" draggable="true" data-token="e:<?=$r['id']?>"><?php foreach($columnOrder as $key):?><td class="<?=$key==='remark'||$key==='date'?'cf-left':''?> <?=$key==='withdrawal'||(($key==='affin'||$key==='total'||$key==='xe_usdt')&&(float)$r[$key]<0)?'cf-negative':''?>"><span class="cf-view-cell"><?php if($key==='date')echo h($r['entry_date']);elseif($key==='remark')echo h($r['remark']);elseif(str_starts_with($key,'extra:'))echo cf_money($extras[(int)$r['id']][(int)substr($key,6)]??0);else echo cf_money($r[$key],$key==='withdrawal');?></span><span class="cf-edit-cell"><?php if($key==='date'):?><input type="date" class="cf-input" name="entries[<?=$r['id']?>][entry_date]" value="<?=h($r['entry_date'])?>" required><?php elseif($key==='remark'):?><input class="cf-input" name="entries[<?=$r['id']?>][remark]" value="<?=h($r['remark'])?>"><?php elseif(str_starts_with($key,'extra:')):$cid=(int)substr($key,6);?><input type="number" step="0.01" class="cf-input" name="entries[<?=$r['id']?>][extra][<?=$cid?>]" value="<?=h($extras[(int)$r['id']][$cid]??0)?>"><?php else:?><input type="number" step="0.01" class="cf-input cf-<?=$key?>" name="entries[<?=$r['id']?>][<?=$key?>]" value="<?=h($r[$key]??'')?>"><?php endif;?></span></td><?php endforeach;?><td class="cf-delete-col"><button type="button" class="cf-icon cf-delete-row" data-id="<?=$r['id']?>">×</button></td></tr><?php endforeach;?>
<tr id="cf-template" class="cf-row" draggable="true" style="display:none"><?php foreach($columnOrder as $key):?><td class="<?=$key==='remark'||$key==='date'?'cf-left':''?>"><?php if($key==='date'):?><input type="date" class="cf-input" data-name="entry_date" value="<?=date('Y-m-d')?>" required><?php elseif($key==='remark'):?><input class="cf-input" data-name="remark"><?php elseif(str_starts_with($key,'extra:')):?><input type="number" step="0.01" class="cf-input" data-name="extra][<?=substr($key,6)?>" value="0"><?php else:?><input type="number" step="0.01" class="cf-input cf-<?=$key?>" data-name="<?=$key?>" value="<?=$key==='total'?'0':''?>"><?php endif;?></td><?php endforeach;?><td class="cf-delete-col"><button type="button" class="cf-icon cf-remove-new">×</button></td></tr>
</tbody><tfoot><tr class="cf-total-row"><?php foreach($columnOrder as $key):?><td class="<?=$key==='remark'?'cf-left':''?>"><?php if($key==='date'):?>Total<?php elseif($key==='deposit'):?><?=cf_money(array_sum(array_column($rows,'deposit')),false,true)?><?php elseif($key==='withdrawal'):?><?=cf_money(array_sum(array_column($rows,'withdrawal')),true,true)?><?php elseif(in_array($key,['affin','total','xe_usdt'],true)):?><?=cf_money(array_sum(array_column($rows,$key)),false,true)?><?php elseif(str_starts_with($key,'extra:')):$cid=(int)substr($key,6);$sum=0;foreach($rows as $rr)$sum+=(float)($extras[(int)$rr['id']][$cid]??0);?><?=cf_money($sum,false,true)?><?php endif;?></td><?php endforeach;?><td class="cf-delete-col"></td></tr></tfoot></table></div><div class="cf-save"><button class="btn btn-primary">Save</button></div></form>
<form method="post" id="cf-action-form" style="display:none"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="company_id" value="<?=$companyId?>"><input type="hidden" name="date_from" value="<?=h($dateFrom)?>"><input type="hidden" name="date_to" value="<?=h($dateTo)?>"><input type="hidden" name="date_all" value="<?=$dateAll?'1':'0'?>"><input type="hidden" name="remark" value="<?=h($remark)?>"><input name="action"><input name="entry_id"><input name="column_id"><input name="column_key"></form>
</div></div></div>
<script>
(function(){const page=document.getElementById('cf-page'),edit=document.getElementById('cf-edit'),add=document.getElementById('cf-add'),body=document.getElementById('cf-body'),form=document.getElementById('cf-form'),action=document.getElementById('cf-action-form');let index=0,dragRow=null,dragCol=null;
if(edit)edit.onclick=()=>{const on=page.classList.toggle('cf-edit-mode');edit.textContent=on?'Cancel':'Edit';add.style.display=on?'inline-flex':'none'};
if(add)add.onclick=()=>{const empty=document.getElementById('cf-empty-row');if(empty)empty.remove();index++;const t=document.getElementById('cf-template'),r=t.cloneNode(true);r.id='';r.style.display='';r.dataset.token='n:'+index;r.querySelectorAll('[data-name]').forEach(i=>i.name='new_rows['+index+']['+i.dataset.name+']');body.insertBefore(r,t)};
body.addEventListener('click',e=>{if(e.target.classList.contains('cf-remove-new'))e.target.closest('tr').remove();if(e.target.classList.contains('cf-delete-row')&&confirm('Delete this entry?')){action.action.value='delete_row';action.entry_id.value=e.target.dataset.id;action.submit()}});
document.querySelectorAll('.cf-delete-column').forEach(b=>b.onclick=()=>{if(confirm('Delete this column and all its values?')){action.action.value='delete_column';action.column_id.value=b.dataset.id;action.submit()}});
document.querySelectorAll('.cf-hide-fixed').forEach(b=>b.onclick=()=>{if(confirm('Remove this column from the cashflow table?')){action.action.value='hide_fixed_column';action.column_key.value=b.dataset.key;action.submit()}});
body.addEventListener('input',e=>{const r=e.target.closest('tr');if(!r||(!e.target.classList.contains('cf-deposit')&&!e.target.classList.contains('cf-withdrawal')))return;const total=r.querySelector('.cf-total'),dep=r.querySelector('.cf-deposit'),wd=r.querySelector('.cf-withdrawal');if(total&&total.dataset.manual!=='1')total.value=((parseFloat(dep?.value)||0)-Math.abs(parseFloat(wd?.value)||0)).toFixed(2)});body.addEventListener('input',e=>{if(e.target.classList.contains('cf-total'))e.target.dataset.manual='1'});
body.addEventListener('dragstart',e=>{dragRow=e.target.closest('tr.cf-row')});body.addEventListener('dragover',e=>{if(dragRow)e.preventDefault()});body.addEventListener('drop',e=>{const target=e.target.closest('tr.cf-row');if(!dragRow||!target||target===dragRow)return;e.preventDefault();const rs=[...body.querySelectorAll('tr.cf-row')];body.insertBefore(dragRow,rs.indexOf(dragRow)<rs.indexOf(target)?target.nextSibling:target);dragRow=null});
const header=document.getElementById('cf-header');header.addEventListener('dragstart',e=>{dragCol=e.target.closest('th[data-key]')});header.addEventListener('dragover',e=>{if(dragCol)e.preventDefault()});header.addEventListener('drop',e=>{const target=e.target.closest('th[data-key]');if(!dragCol||!target||target===dragCol)return;e.preventDefault();const cells=[...header.children],from=cells.indexOf(dragCol),to=cells.indexOf(target);document.querySelectorAll('.cf-table tr').forEach(r=>{const c=r.children[from],t=r.children[to];if(c&&t)r.insertBefore(c,from<to?t.nextSibling:t)});dragCol=null});
form.addEventListener('submit',()=>{document.getElementById('cf-row-order').value=[...body.querySelectorAll('tr.cf-row:not(#cf-template)')].map(r=>r.dataset.token).filter(Boolean).join(',');document.getElementById('cf-column-order').value=JSON.stringify([...header.querySelectorAll('th[data-key]')].map(h=>h.dataset.key))});
})();
</script>
<?php include __DIR__.'/../include/footer.php'; ?>
