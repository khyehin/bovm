<?php
// public/admin/customers/list.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('CUSTOMER.V');

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('tt')) {
  function tt(string $key, string $fallback = ''): string {
    if (function_exists('t')) return (string)t($key, [], $fallback);
    return $fallback !== '' ? $fallback : $key;
  }
}
function rm(float $v): string { return 'RM ' . number_format($v, 2); }

$search = trim($_GET['q'] ?? '');

/* ============================
   AJAX: update customer category (Move to...)
   ============================ */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'set_category') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if (function_exists('require_perm')) require_perm('CUSTOMER.E');

    $cid = (int)($_POST['customer_id'] ?? 0);
    $newCat = (int)($_POST['category_id'] ?? 0);
    if ($cid <= 0) throw new RuntimeException('Invalid customer_id');

    $catVal = ($newCat > 0) ? $newCat : null;

    $st = $pdo->prepare("UPDATE customers SET category_id = :cat WHERE id = :id");
    $st->execute([':cat' => $catVal, ':id' => $cid]);

    echo json_encode(['ok' => 1]);
  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => 0, 'err' => $e->getMessage()]);
  }
  exit;
}

/* ============================
   Categories
   ============================ */
$categories = [];
try {
  $categories = $pdo->query("
    SELECT id, name, sort_order
    FROM customer_categories
    ORDER BY sort_order ASC, id ASC
  ")->fetchAll() ?: [];
} catch (Throwable $e) {
  $categories = [
    ['id'=>1,'name'=>'Category A','sort_order'=>1],
    ['id'=>2,'name'=>'Category B','sort_order'=>2],
    ['id'=>3,'name'=>'Contra / Allocation','sort_order'=>3],
  ];
}

$catMap = [0 => 'Unassigned'];
foreach ($categories as $c) $catMap[(int)$c['id']] = (string)$c['name'];

/* ============================
   Aggregation SQL (SOURCE OF TRUTH)
   ============================ */
$paySumSub = "
  SELECT customer_txn_id, SUM(amount) AS paid_sum
  FROM customer_txn_payments
  GROUP BY customer_txn_id
";

$sql = "
  SELECT
    c.*,
    COALESCE(c.category_id, 0) AS category_id,

    -- IN before contra
    COALESCE(SUM(
      CASE WHEN t.txn_type='IN' THEN t.amount ELSE 0 END
    ),0) AS total_in_before,

    -- IN after contra
    COALESCE(SUM(
      CASE WHEN t.txn_type='IN' THEN (t.amount-COALESCE(t.allocated_amount,0)) ELSE 0 END
    ),0) AS total_in_after,

    -- OUT (non-contra)
    COALESCE(SUM(
      CASE WHEN t.txn_type='OUT' AND (t.is_contra IS NULL OR t.is_contra=0)
      THEN t.amount ELSE 0 END
    ),0) AS total_out_after,

    -- LOAN total
    COALESCE(SUM(
      CASE WHEN t.txn_type='OUT'
        AND (t.is_contra IS NULL OR t.is_contra=0)
        AND UPPER(COALESCE(t.out_kind,''))='LOAN'
      THEN t.amount ELSE 0 END
    ),0) AS loan_total,

    -- Repayment/Return total
    COALESCE(SUM(
      CASE WHEN t.txn_type='IN'
        AND (
          UPPER(COALESCE(t.in_kind,''))='RETURN'
          OR (
            (COALESCE(t.order_total,0)=0) AND (t.amount>0)
            AND (
              t.title LIKE '%Repayment%' OR t.title LIKE '%repayment%'
              OR t.title LIKE '%Return%' OR t.title LIKE '%return%'
            )
          )
        )
      THEN (t.amount-COALESCE(t.allocated_amount,0))
      ELSE 0 END
    ),0) AS repay_total,

    -- Pending unpaid:
    -- ✅ 只要是 IN 且 status != CONFIRMED：pending 一律表示「Customer owes us」
    COALESCE(SUM(
      CASE
        WHEN t.txn_type='IN'
          AND (COALESCE(t.is_contra,0)=0)
          AND UPPER(TRIM(COALESCE(t.status,''))) <> 'CONFIRMED'
          AND UPPER(TRIM(COALESCE(t.doc_flow_status,''))) <> 'REJECTED'
        THEN
          GREATEST(
            0,
            (
              CASE
                -- INVOICE 用 order_total（没有就用 amount）
                WHEN (UPPER(COALESCE(t.in_kind,'')) NOT IN ('BONUS','RETURN'))
                THEN (CASE WHEN COALESCE(t.order_total,0)>0 THEN COALESCE(t.order_total,0) ELSE COALESCE(t.amount,0) END)
                -- RETURN / BONUS 直接用 amount
                ELSE COALESCE(t.amount,0)
              END
            )
            -
            (
              CASE
                WHEN COALESCE(ps.paid_sum,0)>0 THEN COALESCE(ps.paid_sum,0)
                ELSE 0
              END
            )
          )
        ELSE 0
      END
    ),0) AS pending_total

  FROM customers c
  LEFT JOIN customer_txn t ON t.customer_id=c.id
  LEFT JOIN ($paySumSub) ps ON ps.customer_txn_id=t.id
";

$params = [];
$where = [];
if ($search !== '') {
  $where[] = "(c.code LIKE :q OR c.name LIKE :q OR c.reg_no LIKE :q)";
  $params[':q'] = '%'.$search.'%';
}
if ($where) $sql .= " WHERE ".implode(' AND ', $where);

$sql .= " GROUP BY c.id
          ORDER BY COALESCE(c.category_id,0) ASC, c.code ASC, c.name ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll() ?: [];

/* ============================
   Group rows by category
   ============================ */
$groups = [];
foreach ($categories as $cat) $groups[(int)$cat['id']] = [];
$groups[0] = [];

foreach ($rows as $r) {
  $gid = (int)($r['category_id'] ?? 0);
  if (!isset($groups[$gid])) $groups[$gid] = [];

  $inBefore = (float)($r['total_in_before'] ?? 0);
  $inAfter  = (float)($r['total_in_after'] ?? 0);
  $outAfter = (float)($r['total_out_after'] ?? 0);

  $loan  = (float)($r['loan_total'] ?? 0);
  $repay = (float)($r['repay_total'] ?? 0);

  $r['_in_before'] = $inBefore;
  $r['_in_after']  = $inAfter;
  $r['_out_after'] = $outAfter;
  $r['_net_after'] = $inAfter - $outAfter;
  $r['_return']    = $loan - $repay;

  $groups[$gid][] = $r;
}

/* ============================
   Summary
   ============================ */
$summaryByCat = [];
foreach ($groups as $gid => $list) {
  $summaryByCat[$gid] = [
    'count'   => 0,
    'in'      => 0.0,
    'out'     => 0.0,
    'net'     => 0.0,
    'pending' => 0.0,
    'return'  => 0.0,
  ];
  foreach ($list as $r) {
    $summaryByCat[$gid]['count']++;
    $summaryByCat[$gid]['in']      += (float)($r['total_in_before'] ?? 0);
    $summaryByCat[$gid]['out']     += (float)($r['total_out_after'] ?? 0);
    $summaryByCat[$gid]['net']     += (float)($r['_net_after'] ?? 0);
    $summaryByCat[$gid]['pending'] += (float)($r['pending_total'] ?? 0);
    $summaryByCat[$gid]['return']  += (float)($r['_return'] ?? 0);
  }
}

$page_title = tt('admin.customers.list.title', 'Customers');
include __DIR__ . '/../include/header.php';
?>

<style>
/* =========================================================
   GLOBAL SAFETY
   - 防止整页横向滚动（header / footer 走空问题）
   ========================================================= */
html, body {
  max-width: 100%;
  overflow-x: hidden;
}

.admin-shell,
.admin-main,
.admin-main-inner {
  max-width: 100%;
  overflow-x: hidden;
}

/* =========================================================
   PAGE SCOPE（只影响本页）
   ========================================================= */
.customers-page{
  width:100%;
  max-width:100%;
  overflow-x:hidden;
}
.customers-page *{
  box-sizing:border-box;
}

/* =========================================================
   HEADER（标题 + 按钮）
   - 屏幕不够就自动换行往下
   ========================================================= */
.customers-page .admin-card-header{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;        /* ⭐关键 */
}

/* =========================================================
   SEARCH ROW
   ========================================================= */
.customers-page .customers-search-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
  margin-top:12px;
}
.customers-page .customers-search-row form{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
}
.customers-page .customers-search-row input{
  width:260px;
}

/* =========================================================
   TOP SUMMARY GRID（自动 fit 屏幕）
   ========================================================= */
.customers-page .top-grid{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap:12px;
  margin-top:12px;
}

/* summary card */
.customers-page .summary{
  border:1px solid #e5e7eb;
  border-radius:14px;
  background:#fff;
  padding:12px 14px;
}
.customers-page .summary .title{
  font-weight:900;
  font-size:13px;
  color:#111827;
  display:flex;
  justify-content:space-between;
  gap:10px;
  align-items:baseline;
}
.customers-page .summary .mini{
  font-size:11px;
  color:#6b7280;
  font-weight:800;
}

.customers-page .sum-grid{
  display:grid;
  grid-template-columns: repeat(2, minmax(0,1fr));
  gap:10px;
  margin-top:10px;
}
.customers-page .k{
  font-size:10px;
  letter-spacing:.06em;
  text-transform:uppercase;
  color:#6b7280;
}
.customers-page .v{
  font-weight:900;
  font-size:13px;
  margin-top:2px;
  color:#111827;
  white-space:nowrap;
}
.customers-page .v-red{ color:#b91c1c; }
.customers-page .v-green{ color:#166534; }
.customers-page .v-teal{ color:#0f766e; }
.customers-page .v-gray{ color:#6b7280; }
.customers-page .dim{ color:#6b7280; font-size:11px; }

/* =========================================================
   TABLE
   - 只在 table-wrap 横向滚
   ========================================================= */
.customers-page .table-wrap{
  width:100%;
  max-width:100%;
  overflow-x:auto;
  -webkit-overflow-scrolling:touch;
}
.customers-page .table-wrap table{
  width:100%;
  min-width:0 !important;   /* ⭐避免撑破页面 */
}

.customers-page .table th,
.customers-page .table td{
  vertical-align:middle;
}
.customers-page .money{
  text-align:right;
  white-space:nowrap;
  font-variant-numeric: tabular-nums;
}

/* group header row */
.customers-page .group-row td{
  background:#f8fafc;
  border-top:1px solid #e5e7eb;
  border-bottom:1px solid #e5e7eb;
  padding:10px 12px;
}
.customers-page .group-title{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
}
.customers-page .group-title .left{
  font-weight:900;
  font-size:13px;
  color:#111827;
}
.customers-page .group-title .right{
  font-size:12px;
  color:#6b7280;
  font-weight:800;
}

/* =========================================================
   BADGES
   ========================================================= */
.customers-page .pbadge{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:3px 8px;
  border-radius:999px;
  font-size:11px;
  font-weight:900;
  border:1px solid transparent;
}
.customers-page .pbadge.recv{
  background:#fff1f2;
  color:#b91c1c;
  border-color:#fecaca;
}

.customers-page .badge{
  font-size:11px;
  padding:3px 9px;
  border-radius:999px;
  display:inline-block;
}
.customers-page .badge.active{
  background:#ecfdf5;
  color:#166534;
}
.customers-page .badge.inactive{
  background:#fee2e2;
  color:#b91c1c;
}

/* =========================================================
   ACTIONS MENU
   ========================================================= */
.customers-page .actions-cell{
  vertical-align:middle;
}
.customers-page .actions-menu{
  position:relative;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  height:32px;
}
.customers-page .actions-toggle{
  border:1px solid #e5e7eb;
  background:#fff;
  border-radius:999px;
  height:32px;
  min-width:32px;
  padding:0 8px;
  cursor:pointer;
  font-size:16px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
}
.customers-page .actions-toggle:hover{
  background:#f3f4f6;
}
.customers-page .actions-toggle span{
  letter-spacing:2px;
}

.customers-page .actions-dropdown{
  position:absolute;
  right:0;
  top:36px;
  min-width:210px;
  background:#fff;
  border-radius:10px;
  box-shadow:0 10px 30px rgba(15,23,42,.12);
  border:1px solid #e5e7eb;
  padding:6px 0;
  z-index:50;
  display:none;
}
.customers-page .actions-dropdown a{
  display:block;
  padding:7px 12px;
  font-size:13px;
  color:#374151;
  text-decoration:none;
}
.customers-page .actions-dropdown a:hover{
  background:#f3f4f6;
}

.customers-page .move-wrap{
  padding:8px 12px;
  border-top:1px solid #f1f5f9;
}
.customers-page .move-label{
  font-size:11px;
  color:#6b7280;
  margin-bottom:6px;
  font-weight:800;
}
.customers-page .move-select{
  width:100%;
  border:1px solid #e5e7eb;
  border-radius:10px;
  padding:6px 8px;
  font-size:12px;
  background:#fff;
}

/* =========================================================
   RESPONSIVE（小屏自动往下）
   ========================================================= */
@media (max-width: 900px){
  .customers-page .admin-card-header > div:last-child{
    width:100%;
    justify-content:flex-start;
  }

  .customers-page .customers-search-row input,
  .customers-page .customers-search-row form{
    width:100%;
  }
}
</style>

<div class="customers-page">
  <div class="admin-main">
    <div class="admin-main-inner">

      <div class="admin-card" style="margin-bottom:14px;">
        <div class="admin-card-header">
          <div>
            <div class="form-page-eyebrow"><?= h(tt('admin.customers.list.eyebrow','Master data')) ?></div>
            <h1 class="page-title"><?= h($page_title) ?></h1>
          </div>

          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="<?= h(url('admin/customers/categories.php')) ?>" class="btn btn-light">
              <?= h(tt('admin.customers.list.manage_cat','Manage Categories')) ?>
            </a>
            <a href="<?= h(url('admin/customers/edit.php?id=0')) ?>" class="btn btn-primary">
              <?= h(tt('admin.customers.list.new_btn','+ New Customer')) ?>
            </a>
          </div>
        </div>

        <div class="top-grid">
          <?php foreach ($categories as $cat): ?>
            <?php
              $gid = (int)$cat['id'];
              $s = $summaryByCat[$gid] ?? ['count'=>0,'in'=>0,'out'=>0,'net'=>0,'pending'=>0,'return'=>0];

              $net = (float)$s['net'];
              $pending = (float)$s['pending'];
              $ret = (float)$s['return'];

              $netClass = $net > 0 ? 'v-red' : ($net < 0 ? 'v-green' : 'v-gray');

              // ✅ Summary pending：只要有 pending 就一律红 + customer owes us（不再跟 net）
              $pendClass = ($pending > 0.0001) ? 'v-red' : 'v-gray';

              $retClass = $ret > 0 ? 'v-red' : ($ret < 0 ? 'v-green' : 'v-gray');
            ?>
            <div class="summary">
              <div class="title">
                <span><?= h($cat['name']) ?></span>
                <span class="mini"><?= (int)$s['count'] ?> companies</span>
              </div>
              <div class="sum-grid">
                <div>
                  <div class="k">IN</div>
                  <div class="v"><?= h(rm((float)$s['in'])) ?></div>
                  <div class="dim">before contra</div>
                </div>
                <div>
                  <div class="k">OUT</div>
                  <div class="v"><?= h(rm((float)$s['out'])) ?></div>
                </div>
                <div>
                  <div class="k">NET</div>
                  <div class="v <?= h($netClass) ?>"><?= h(rm($net)) ?></div>
                </div>
                <div>
                  <div class="k">PENDING</div>
                  <div class="v <?= h($pendClass) ?>"><?= h(rm($pending)) ?></div>
                </div>
                <div>
                  <div class="k">RETURN</div>
                  <div class="v <?= h($retClass) ?>"><?= h(rm($ret)) ?></div>
                </div>
                <div><div class="k"></div><div class="v"></div></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="customers-search-row">
          <form method="get" action="<?= h(url('admin/customers/list.php')) ?>">
            <input type="text" name="q" class="form-control"
                   placeholder="<?= h(tt('admin.customers.list.search_ph','Search by code / name / reg no...')) ?>"
                   value="<?= h($search) ?>">
            <button type="submit" class="btn btn-light"><?= h(tt('admin.common.search','Search')) ?></button>
            <?php if ($search !== ''): ?>
              <a href="<?= h(url('admin/customers/list.php')) ?>" class="btn btn-light"><?= h(tt('admin.common.reset','Reset')) ?></a>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <div class="admin-card">
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:90px;"><?= h(tt('admin.customers.list.col.code','Code')) ?></th>
                <th><?= h(tt('admin.customers.list.col.name','Name')) ?></th>

                <th class="money" style="width:160px;"><?= h(tt('admin.customers.list.col.in_before','IN (before contra)')) ?></th>
                <th class="money" style="width:160px;"><?= h(tt('admin.customers.list.col.in_after','IN (after contra)')) ?></th>
                <th class="money" style="width:160px;"><?= h(tt('admin.customers.list.col.out_after','OUT (real payout)')) ?></th>

                <th class="money" style="width:200px;"><?= h(tt('admin.customers.list.col.net_after','Net (after contra)')) ?></th>
                <th style="width:240px;"><?= h(tt('admin.customers.list.col.pending','Pending')) ?></th>
                <th class="money" style="width:210px;"><?= h(tt('admin.customers.list.col.return','Return')) ?></th>

                <th style="width:90px;"><?= h(tt('admin.customers.list.col.status','Status')) ?></th>
                <th style="width:110px;"><?= h(tt('admin.customers.list.col.actions','Actions')) ?></th>
              </tr>
            </thead>

            <tbody>
            <?php
              $renderOrder = array_map(fn($x)=>(int)$x['id'], $categories);
              $renderOrder[] = 0;

              $backToList = url('admin/customers/list.php') . ($search!=='' ? ('?q='.rawurlencode($search)) : '');
            ?>

            <?php foreach ($renderOrder as $gid): ?>
              <?php
                $list = $groups[$gid] ?? [];
                $title = $catMap[$gid] ?? 'Category';
              ?>

              <tr class="group-row">
                <td colspan="10">
                  <div class="group-title">
                    <div class="left"><?= h($title) ?></div>
                    <div class="right"><?= count($list) ?> companies</div>
                  </div>
                </td>
              </tr>

              <?php if (!$list): ?>
                <tr>
                  <td colspan="10" style="padding:14px; color:#6b7280;">
                    <?= h(tt('admin.customers.list.empty','No customers found.')) ?>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($list as $c): ?>
                  <?php
                    $inBefore = (float)($c['_in_before'] ?? 0);
                    $inAfter  = (float)($c['_in_after'] ?? 0);
                    $outAfter = (float)($c['_out_after'] ?? 0);

                    $net      = (float)($c['_net_after'] ?? 0);
                    $pending  = (float)($c['pending_total'] ?? 0);
                    $ret      = (float)($c['_return'] ?? 0);

                    $netColor = $net > 0 ? '#b91c1c' : ($net < 0 ? '#166534' : '#6b7280');
                    $netText = ($net > 0)
                      ? tt('admin.customers.net.we_owe','We owe customer')
                      : (($net < 0)
                          ? tt('admin.customers.net.cust_owe','Customer owes us')
                          : tt('admin.customers.net.balanced','Balanced'));

                    // ✅ Pending：只要有 pending，一律 Customer owes us（红）
                    $pendLabel = tt('admin.customers.pending.cust_owe','Customer owes us');
                    $pendNumColor = '#b91c1c';

                    $retColor = $ret > 0 ? '#b91c1c' : ($ret < 0 ? '#166534' : '#6b7280');

                    $txnUrl  = url('admin/customers/txn_list.php?customer_id='.(int)$c['id'].'&back='.rawurlencode($backToList));
                    $userUrl = url('admin/customers/user_list.php?customer_id='.(int)$c['id'].'&back='.rawurlencode($backToList));
                    $editUrl = url('admin/customers/edit.php?id='.(int)$c['id']);
                  ?>

                  <tr data-customer-id="<?= (int)$c['id'] ?>">
                    <td><?= h($c['code'] ?? '') ?></td>

                    <td>
                      <div style="font-size:13px;font-weight:800;"><?= h($c['name'] ?? '') ?></div>
                      <?php if (!empty($c['reg_no'])): ?>
                        <div class="dim">Reg: <?= h($c['reg_no']) ?></div>
                      <?php endif; ?>
                    </td>

                    <td class="money"><?= h(rm($inBefore)) ?></td>
                    <td class="money"><?= h(rm($inAfter)) ?></td>
                    <td class="money"><?= h(rm($outAfter)) ?></td>

                    <td class="money">
                      <div style="font-weight:900;color:<?= h($netColor) ?>;"><?= h(rm($net)) ?></div>
                      <div class="dim"><?= h($netText) ?></div>
                    </td>

                    <td>
                      <?php if ($pending <= 0.0001): ?>
                        <span class="dim">—</span>
                      <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                          <span class="pbadge recv"><?= h($pendLabel) ?></span>
                          <span style="font-weight:900; color:<?= h($pendNumColor) ?>;">
                            <?= h(rm($pending)) ?>
                          </span>
                        </div>
                      <?php endif; ?>
                    </td>

                    <td class="money">
                      <?php if (abs($ret) <= 0.0001): ?>
                        <span class="dim">—</span>
                      <?php else: ?>
                        <div style="font-weight:900;color:<?= h($retColor) ?>;"><?= h(rm($ret)) ?></div>
                        <div class="dim">
                          <?= $ret > 0
                            ? h(tt('admin.customers.return.outstanding','Outstanding (customer owes us)'))
                            : h(tt('admin.customers.return.fully','Fully returned')) ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <?php if ((int)($c['is_active'] ?? 1) === 1): ?>
                        <span class="badge active"><?= h(tt('admin.common.active','Active')) ?></span>
                      <?php else: ?>
                        <span class="badge inactive"><?= h(tt('admin.common.inactive','Inactive')) ?></span>
                      <?php endif; ?>
                    </td>

                    <td class="actions-cell">
                      <div class="actions-menu">
                        <button type="button" class="actions-toggle" aria-haspopup="true"><span>⋯</span></button>

                        <div class="actions-dropdown">
                          <a href="<?= h($txnUrl) ?>"><?= h(tt('admin.customers.action.txn','Transactions')) ?></a>
                          <a href="<?= h($userUrl) ?>"><?= h(tt('admin.customers.action.users','Login users')) ?></a>
                          <a href="<?= h($editUrl) ?>"><?= h(tt('admin.customers.action.edit','Edit customer')) ?></a>

                          <div class="move-wrap">
                            <div class="move-label"><?= h(tt('admin.customers.action.move','Move to')) ?></div>
                            <select class="move-select js-move" data-customer-id="<?= (int)$c['id'] ?>">
                              <option value="0" <?= ((int)($c['category_id'] ?? 0)===0?'selected':'') ?>>Unassigned</option>
                              <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>" <?= ((int)($c['category_id'] ?? 0)===(int)$cat['id']?'selected':'') ?>>
                                  <?= h($cat['name']) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                            <div class="dim js-move-msg"></div>
                          </div>
                        </div>

                      </div>
                    </td>
                  </tr>

                <?php endforeach; ?>
              <?php endif; ?>

            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll(".actions-menu").forEach(function (menu) {
    const btn = menu.querySelector(".actions-toggle");
    const dd  = menu.querySelector(".actions-dropdown");
    if (!btn || !dd) return;

    btn.addEventListener("click", function (e) {
      e.stopPropagation();
      const isOpen = dd.style.display === "block";
      document.querySelectorAll(".actions-dropdown").forEach(d => d.style.display = "none");
      dd.style.display = isOpen ? "none" : "block";
    });

    dd.addEventListener("click", function(e){
      e.stopPropagation();
    });
  });

  document.addEventListener("click", function () {
    document.querySelectorAll(".actions-dropdown").forEach(d => d.style.display = "none");
  });

  document.querySelectorAll(".js-move").forEach(function(sel){
    sel.addEventListener("change", async function(){
      const cid = sel.getAttribute("data-customer-id") || "";
      const wrap = sel.closest(".move-wrap");
      const msg = wrap ? wrap.querySelector(".js-move-msg") : null;
      if (msg) msg.textContent = "Saving...";

      const fd = new FormData();
      fd.append("ajax", "set_category");
      fd.append("customer_id", cid);
      fd.append("category_id", sel.value);

      try{
        const res = await fetch(location.href, { method:"POST", body: fd });
        const data = await res.json();
        if (!data || !data.ok) throw new Error((data && data.err) ? data.err : "Save failed");
        if (msg) msg.textContent = "Saved";
        setTimeout(()=>location.reload(), 250);
      }catch(e){
        if (msg) msg.textContent = "Error: " + (e.message || e);
      }
    });
  });
});
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>
