<?php
// config/bootstrap.php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 1) Load base configs
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/company.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/upload.php';
require_once __DIR__ . '/../app/customer_currency.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/permissions.php';

/*
|--------------------------------------------------------------------------
| 2) Composer autoload
|--------------------------------------------------------------------------
*/
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

/*
|--------------------------------------------------------------------------
| 3) Unified audit_log() (BOVM schema version)
|--------------------------------------------------------------------------
| Your table columns expected:
|   audit_logs(user_id, action, ref_table, ref_id, meta, created_at)
|
| Compatible calls:
|   audit_log($pdo, 'LOGIN');
|   audit_log($pdo, 'LOGIN', ['description'=>'xx']);
|   audit_log($pdo, 'TXN.UPDATE', 'customer_txn', 99, ['x'=>1]);
|   audit_log($pdo, 'TXN.UPDATE', 99, ['x'=>1]);
|   audit_log($pdo, ['description'=>'xx']);  // still ok
*/
if (!function_exists('audit_log')) {
    function audit_log(PDO $pdo, ...$args): void
    {
        try {
            if (PHP_SAPI === 'cli') return;

            // args = [$pdo, ...]
            $params = $args;
            array_shift($params); // remove $pdo

            $action   = null;
            $refTable = null;
            $refId    = null;
            $meta     = [];

            foreach ($params as $p) {
                if (is_string($p)) {
                    if ($action === null) {
                        $action = trim($p) !== '' ? trim($p) : null;
                    } elseif ($refTable === null) {
                        $refTable = trim($p) !== '' ? trim($p) : null;
                    } else {
                        // 多余 string 不用管
                    }
                } elseif (is_int($p) || (is_string($p) && ctype_digit($p))) {
                    if ($refId === null) $refId = (int)$p;
                } elseif (is_array($p)) {
                    // 最后一个 array 当 meta（如果出现多个 array，就 merge）
                    $meta = array_merge($meta, $p);
                }
            }

            if ($action === null) $action = 'AUDIT';

            // current user
            $userId = null;
            if (function_exists('current_user')) {
                $u = current_user();
                if (is_array($u) && isset($u['id'])) $userId = (int)$u['id'];
            }

            // attach IP/UA into meta (so log_list can show)
            $meta['_ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
            $meta['_ua'] = $_SERVER['HTTP_USER_AGENT'] ?? null;

            // meta JSON
            $metaJson = !empty($meta)
                ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            $st = $pdo->prepare("
                INSERT INTO audit_logs
                  (user_id, action, ref_table, ref_id, meta, created_at)
                VALUES
                  (:uid, :action, :rt, :rid, :meta, NOW())
            ");
            $st->execute([
                ':uid'    => $userId,
                ':action' => $action,
                ':rt'     => $refTable,
                ':rid'    => $refId,
                ':meta'   => $metaJson,
            ]);
        } catch (Throwable $e) {
            // 不影响主流程
            // error_log('[audit_log] '.$e->getMessage());
        }
    }
}
