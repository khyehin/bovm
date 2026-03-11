<?php
// public/admin/audit_logs/log_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
    require_perm('AUDIT.V');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$tt = function (string $key, string $fallback): string {
    if (function_exists('t')) return (string)t($key, [], $fallback);
    return $fallback;
};

// ---- Filters ----
$today = date('Y-m-d');
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
$user_id   = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$action    = trim($_GET['action'] ?? '');
$q         = trim($_GET['q'] ?? '');

// 默认最近 7 天（date_range 组件）
if ($date_from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-d', strtotime('-7 days'));
}
if ($date_to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = $today;
}

// ---- Users list ----
$users = [];
try {
    $st = $pdo->query("SELECT id, username, full_name FROM users ORDER BY username ASC");
    $users = $st->fetchAll();
} catch (Throwable $e) {
    $users = [];
}

// ---- Distinct actions (IMPORTANT: your column is `action`) ----
$actions = [];
try {
    $st = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");
    $actions = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $actions = [];
}

// ---- WHERE ----
$where   = [];
$params  = [];

$where[]       = "DATE(l.created_at) BETWEEN :d1 AND :d2";
$params[':d1'] = $date_from;
$params[':d2'] = $date_to;

if ($user_id > 0) {
    $where[]        = "l.user_id = :uid";
    $params[':uid'] = $user_id;
}
if ($action !== '') {
    $where[]         = "l.action = :act";
    $params[':act']  = $action;
}
if ($q !== '') {
    $where[] = "(
          l.action LIKE :q
       OR l.ref_table LIKE :q
       OR l.meta LIKE :q
    )";
    $params[':q'] = '%'.$q.'%';
}

$whereSql = $where ? implode(' AND ', $where) : '1=1';

// ---- Pagination ----
$perPage = 20;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$sqlCount = "SELECT COUNT(*) FROM audit_logs l WHERE {$whereSql}";
$st = $pdo->prepare($sqlCount);
$st->execute($params);
$totalRows  = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// ---- Query ----
$sql = "
    SELECT
        l.*,
        u.username,
        u.full_name
      FROM audit_logs l
 LEFT JOIN users u ON u.id = l.user_id
     WHERE {$whereSql}
  ORDER BY l.created_at DESC, l.id DESC
  LIMIT {$perPage} OFFSET {$offset}
";
$st = $pdo->prepare($sql);
$st->execute($params);
$logs = $st->fetchAll();

// ---- Audit: view page itself (optional) ----
if (function_exists('audit_log')) {
    audit_log($pdo, 'AUDIT.VIEW', 'audit_logs', null, [
        'date_from' => $date_from,
        'date_to'   => $date_to,
        'user_id'   => $user_id ?: null,
        'action'    => $action ?: null,
        'search'    => $q ?: null,
        'page'      => $page,
    ]);
}

$page_title = $tt('admin.audit.title', 'Audit Logs');
include __DIR__ . '/../include/header.php';
?>

<style>
  .audit-extra{
    max-width:260px;font-size:11px;color:#4b5563;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  }
  .badge-user{font-size:11px;padding:2px 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;}
  .badge-action{font-size:11px;padding:2px 8px;border-radius:999px;background:#f3e8ff;color:#6b21a8;}
  .table-responsive{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;}
  .table{table-layout:fixed;}
  .table th,.table td{word-wrap:break-word;word-break:break-word;}
</style>

<div class="admin-card admin-card-elevated" style="margin-bottom:18px;">
  <div class="admin-card-header">
    <div>
      <div class="form-page-eyebrow"><?= h($tt('admin.audit.eyebrow','Security')) ?></div>
      <h1 class="page-title"><?= h($page_title) ?></h1>
      <div class="form-page-subtitle">
        <?= h(sprintf($tt('admin.audit.subtitle','Track who did what and when. Showing %d logs per page.'), (int)$perPage)) ?>
      </div>
    </div>
  </div>

  <form method="get" action="<?= h(url('admin/audit_logs/log_list.php')) ?>"
        style="margin-top:10px;margin-bottom:8px;display:flex;flex-direction:column;gap:10px;">

    <?php include __DIR__ . '/../../include/date_range.php'; ?>

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
      <div style="display:flex;flex-direction:column;gap:4px;min-width:180px;">
        <label style="font-size:12px;color:#4b5563;"><?= h($tt('admin.audit.filter.user','User')) ?></label>
        <select name="user_id" class="form-control">
          <option value="0"><?= h($tt('admin.audit.filter.user_all','All users')) ?></option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $user_id === (int)$u['id'] ? 'selected' : '' ?>>
              <?= h(($u['username'] ?: 'user#'.$u['id']).($u['full_name'] ? ' · '.$u['full_name'] : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:flex;flex-direction:column;gap:4px;min-width:200px;">
        <label style="font-size:12px;color:#4b5563;"><?= h($tt('admin.audit.filter.action','Action')) ?></label>
        <select name="action" class="form-control">
          <option value=""><?= h($tt('admin.audit.filter.action_all','All actions')) ?></option>
          <?php foreach ($actions as $ac): ?>
            <option value="<?= h($ac) ?>" <?= $action === $ac ? 'selected' : '' ?>><?= h($ac) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:flex;flex-direction:column;gap:4px;min-width:220px;">
        <label style="font-size:12px;color:#4b5563;"><?= h($tt('admin.audit.filter.keyword','Keyword')) ?></label>
        <input type="text" name="q" class="form-control"
               placeholder="<?= h($tt('admin.audit.filter.keyword_ph','Search action / ref_table / meta...')) ?>"
               value="<?= h($q) ?>">
      </div>

      <div style="display:flex;gap:8px;margin-left:auto;">
        <button type="submit" class="btn btn-primary"><?= h($tt('admin.common.apply','Apply')) ?></button>
        <a href="<?= h(url('admin/audit_logs/log_list.php')) ?>" class="btn btn-light"><?= h($tt('admin.common.reset','Reset')) ?></a>
      </div>
    </div>
  </form>
</div>

<div class="admin-card">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th colspan="7">
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#6b7280;">
              <div><?= h(sprintf($tt('admin.audit.total_line','Total: %d records · %d per page'), (int)$totalRows, (int)$perPage)) ?></div>
              <div><?= h(sprintf($tt('admin.audit.page_line','Page %d / %d'), (int)$page, (int)$totalPages)) ?></div>
            </div>
          </th>
        </tr>
        <tr>
          <th><?= h($tt('admin.audit.col.time','Time')) ?></th>
          <th><?= h($tt('admin.audit.col.user','User')) ?></th>
          <th><?= h($tt('admin.audit.col.action','Action')) ?></th>
          <th><?= h($tt('admin.audit.col.entity','Entity')) ?></th>
          <th><?= h($tt('admin.audit.col.description','Description')) ?></th>
          <th><?= h($tt('admin.audit.col.extra','Extra')) ?></th>
          <th><?= h($tt('admin.audit.col.ip','IP')) ?></th>
        </tr>
      </thead>

      <tbody>
      <?php if (!$logs): ?>
        <tr>
          <td colspan="7" style="padding:14px;font-size:13px;color:#6b7280;">
            <?= h($tt('admin.audit.empty','No audit records found for this filter.')) ?>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($logs as $log): ?>
          <?php
            $time = (string)($log['created_at'] ?? '');

            $userLabel = trim(((string)($log['username'] ?? '')).' '.((string)($log['full_name'] ?? '')));
            if ($userLabel === '') $userLabel = $tt('admin.audit.system_unknown','System / Unknown');

            $actionCode = (string)($log['action'] ?? '');
            $entityType = (string)($log['ref_table'] ?? '');
            $entityId   = $log['ref_id'] ?? null;

            // meta decode
            $metaArr = [];
            $metaRaw = (string)($log['meta'] ?? '');
            if ($metaRaw !== '') {
                $decoded = json_decode($metaRaw, true);
                if (is_array($decoded)) $metaArr = $decoded;
            }

            $desc = '';
            if (isset($metaArr['description']) && is_scalar($metaArr['description'])) {
                $desc = (string)$metaArr['description'];
                unset($metaArr['description']);
            }

            // build extra string
            $extraText = '';
            if (!empty($metaArr)) {
                $pairs = [];
                foreach ($metaArr as $k => $v) {
                    if (is_scalar($v) || $v === null) $pairs[] = $k.': '.(string)$v;
                    else $pairs[] = $k.': '.json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                }
                $extraText = implode('; ', $pairs);
            }

            $extraShort = $extraText;
            if ($extraShort !== '' && mb_strlen($extraShort) > 140) {
                $extraShort = mb_substr($extraShort, 0, 140).'…';
            }

            $ip = '';
            if (isset($metaArr['_ip']) && is_scalar($metaArr['_ip'])) $ip = (string)$metaArr['_ip'];
          ?>
          <tr>
            <td><?= h($time) ?></td>
            <td><span class="badge-user"><?= h($userLabel) ?></span></td>
            <td>
              <?php if ($actionCode !== ''): ?>
                <span class="badge-action"><?= h($actionCode) ?></span>
              <?php else: ?>-<?php endif; ?>
            </td>
            <td>
              <?php if ($entityType !== ''): ?>
                <div style="font-size:12px;font-weight:500;"><?= h($entityType) ?></div>
                <?php if ($entityId !== null && $entityId !== ''): ?>
                  <div style="font-size:11px;color:#6b7280;">#<?= (int)$entityId ?></div>
                <?php endif; ?>
              <?php else: ?>-<?php endif; ?>
            </td>
            <td style="font-size:12px;"><?= $desc !== '' ? h($desc) : '-' ?></td>
            <td>
              <?php if ($extraShort !== ''): ?>
                <div class="audit-extra" title="<?= h($extraText) ?>"><?= h($extraShort) ?></div>
              <?php else: ?>
                <span style="font-size:11px;color:#9ca3af;">–</span>
              <?php endif; ?>
            </td>
            <td style="font-size:11px;"><?= $ip !== '' ? h($ip) : '-' ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div style="padding-top:10px;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#6b7280;">
      <div><?= h(sprintf($tt('admin.audit.page_line','Page %d / %d'), (int)$page, (int)$totalPages)) ?></div>
      <div style="display:flex;gap:6px;">
        <?php $baseParams = $_GET; unset($baseParams['page']); ?>

        <?php if ($page > 1): ?>
          <a class="btn btn-light"
             href="<?= h(url('admin/audit_logs/log_list.php?'.http_build_query($baseParams + ['page' => $page-1]))) ?>">
            ← <?= h($tt('admin.common.prev','Prev')) ?>
          </a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
          <a class="btn btn-light"
             href="<?= h(url('admin/audit_logs/log_list.php?'.http_build_query($baseParams + ['page' => $page+1]))) ?>">
            <?= h($tt('admin.common.next','Next')) ?> →
          </a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
