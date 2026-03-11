<?php
// app/audit.php
// 这里只负责「读取」 audit_logs，不再定义 audit_log()（避免重复）

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * 取得 audit_logs 列表（可选 filter）
 *
 * @param string|null $action      动作 code，例如 CUSTOMER.CREATE / LOGIN
 * @param int|null    $user_id     用户 id
 * @param string|null $date_from   YYYY-MM-DD
 * @param string|null $date_to     YYYY-MM-DD
 * @return array
 */
function audit_fetch_logs(
    ?string $action = null,
    ?int $user_id = null,
    ?string $date_from = null,
    ?string $date_to = null
): array
{
    $pdo = get_pdo();

    $sql = "SELECT a.*, u.username, u.full_name
              FROM audit_logs a
              LEFT JOIN users u ON u.id = a.user_id
             WHERE 1 = 1";

    $params = [];

    if ($action) {
        $sql .= " AND a.action = :ac";
        $params[':ac'] = $action;
    }

    if ($user_id) {
        $sql .= " AND a.user_id = :uid";
        $params[':uid'] = $user_id;
    }

    if ($date_from) {
        $sql .= " AND DATE(a.created_at) >= :d1";
        $params[':d1'] = $date_from;
    }

    if ($date_to) {
        $sql .= " AND DATE(a.created_at) <= :d2";
        $params[':d2'] = $date_to;
    }

    $sql .= " ORDER BY a.created_at DESC, a.id DESC";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll();
}
