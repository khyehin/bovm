<?php
declare(strict_types=1);

if (!function_exists('audit_log')) {
    function audit_log(PDO $pdo, string $actionCode, ?int $entityId = null, array $meta = []): void
    {
        try {
            if (PHP_SAPI === 'cli') return;

            $userId = null;
            if (function_exists('current_user')) {
                $u = current_user();
                if (is_array($u)) {
                    $userId = $u['id'] ?? null;
                }
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            // 推断 entity_type
            $entityType = null;
            if ($entityId !== null) {
                if (str_starts_with($actionCode, 'CUSTOMER_')) {
                    $entityType = 'customer';
                } elseif (str_starts_with($actionCode, 'CUST_TXN_') || str_starts_with($actionCode, 'ALLOCATE_')) {
                    $entityType = 'customer_txn';
                }
            }

            // description
            $description = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

            $sql = "
                INSERT INTO audit_logs (
                    created_at,
                    user_id,
                    action_code,
                    description,
                    entity_type,
                    entity_id,
                    ip_address
                ) VALUES (
                    NOW(), :uid, :ac, :desc, :etype, :eid, :ip
                )
            ";

            $st = $pdo->prepare($sql);
            $st->execute([
                ':uid'   => $userId,
                ':ac'    => $actionCode,
                ':desc'  => $description,
                ':etype' => $entityType,
                ':eid'   => $entityId,
                ':ip'    => $ip,
            ]);

        } catch (Throwable $e) {
            // 不影响主流程
        }
    }
}
