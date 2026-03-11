<?php
// app/permissions.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';

/**
 * All permission codes in the system.
 *
 * Code strings must match:
 *  - can('XXX')
 *  - require_perm('XXX')
 *  - any checks in PHP pages
 */
function all_permissions(): array
{
    return [
        // ----- App / Dashboard -----
        'APP.DASH.V'        => 'View dashboard',

        // ----- Customers & portal logins -----
        'CUSTOMER.V'        => 'View customers & their transactions',
        'CUSTOMER.E'        => 'Create / edit customers',
        'CUSTOMER.USER'     => 'Manage customer portal logins',

        // ----- Payer master data (companies & staff) -----
        'PAYER.MNG'         => 'Manage all payer master data',
        'PAYER.COMPANY.V'   => 'View payer companies',
        'PAYER.COMPANY.E'   => 'Create / edit payer companies',
        'PAYER.STAFF.V'     => 'View payer staff',
        'PAYER.STAFF.E'     => 'Create / edit payer staff',

        // (legacy) if you still use payer-related bank mapping
        'PAYER.BANK.E'      => 'Manage payer company banks',

        // ----- Customer transactions (IN / OUT) -----
        'TXN.V'             => 'View IN / OUT transactions',
        'TXN.E'             => 'Create / edit IN / OUT transactions',
        'TXN.D'             => 'Delete IN / OUT transactions',
        'TXN.ALLOC'         => 'Allocate IN between customers (contra)',

        // ----- Company banks (new module) -----
        // You already used BANK.MNG in bank pages
        'BANK.MNG'          => 'Manage company bank accounts & transactions',
        'BANK.V'            => 'View bank accounts',
        'BANK.E'            => 'Create / edit bank accounts',
        'BANK.ACCOUNT.V'    => 'View company bank accounts',
        'BANK.ACCOUNT.E'    => 'Create / edit company bank accounts',
        'BANK.TXN.V'        => 'View company bank transactions',
        'BANK.TXN.E'        => 'Create / edit company bank transactions',
        'BANK.STMT.V'       => 'View uploaded bank statements',
        'BANK.STMT.E'       => 'Upload / delete bank statements',

        // ----- Reports -----
        'REPORT.V'          => 'View reports',

        // ----- Admin users & roles -----
        'USER.MNG'          => 'Manage internal admin users',
        'ROLE.MNG'          => 'Manage roles & permissions',

        // ----- Audit logs -----
        'AUDIT.V'           => 'View audit logs',
    ];
}

/**
 * Fetch all permission codes for a given user
 * via user_roles + role_permissions.
 */
function user_permissions(int $userId): array
{
    static $cache = [];

    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $pdo = get_pdo();

    $sql = "
        SELECT DISTINCT rp.perm_code
          FROM user_roles ur
          JOIN roles r
            ON r.id = ur.role_id
           AND r.is_active = 1
          JOIN role_permissions rp
            ON rp.role_id = r.id
         WHERE ur.user_id = :uid
    ";

    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $userId]);
    $rows = $st->fetchAll(\PDO::FETCH_COLUMN);

    $cache[$userId] = $rows ?: [];
    return $cache[$userId];
}

/**
 * Check whether current user has a permission code.
 *
 * Logic:
 *  - Not logged in          → false
 *  - role != ADMIN          → false (customer portal is handled separately)
 *  - If ADMIN:
 *      * If this admin has NO role records in user_roles
 *          → treat as super admin (all permissions = true)
 *      * Else
 *          → use role_permissions.perm_code
 */
function user_has_perm(string $perm): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }

    if (($u['role'] ?? '') !== 'ADMIN') {
        return false;
    }

    $uid = (int)$u['id'];

    $pdo = get_pdo();
    $st  = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = :uid");
    $st->execute([':uid' => $uid]);
    $roleCount = (int)$st->fetchColumn();

    // Admin without any roles → super admin → all permissions granted
    if ($roleCount === 0) {
        return true;
    }

    $perms = user_permissions($uid);

    return in_array($perm, $perms, true);
}

/**
 * Alias: can($perm)
 */
function can(string $perm): bool
{
    return user_has_perm($perm);
}

/**
 * Enforce a permission. If missing, exit 403.
 */
function require_perm(string $perm): void
{
    // ensure admin login first
    require_admin();

    if (!user_has_perm($perm)) {
        http_response_code(403);
        exit('Forbidden (missing permission: ' .
            htmlspecialchars($perm, ENT_QUOTES, 'UTF-8') .
            ')');
    }
}
