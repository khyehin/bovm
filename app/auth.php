<?php
// app/auth.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 读取当前登录用户（缓存一份）
 */
function current_user(): ?array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!isset($_SESSION['user_id'])) {
        return $cached = null;
    }

    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return $cached = null;
    }

    $pdo = get_pdo();
    $st  = $pdo->prepare("SELECT * FROM users WHERE id = :id AND is_active = 1");
    $st->execute([':id' => $uid]);
    $row = $st->fetch();

    return $cached = ($row ?: null);
}

/**
 * 登录校验
 *  - 成功返回 user row
 *  - 失败返回 null
 */
function auth_login(string $username, string $password): ?array
{
    $pdo = get_pdo();
    $st  = $pdo->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
    $st->execute([':u' => $username]);
    $user = $st->fetch();

    if (!$user) {
        return null;
    }

    $hash = $user['password_hash'] ?? '';

    // 使用 password_hash 的情况
    if ($hash && password_get_info($hash)['algo'] !== 0) {
        if (!password_verify($password, $hash)) {
            return null;
        }
    } else {
        // 旧系统明文兼容（如果不需要可以删掉这段）
        if ($password !== $hash) {
            return null;
        }
    }

    return $user;
}

/**
 * 登录成功后：写 session + 更新 last_login_at + 记 audit
 */
function auth_set_user(array $user): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['user_id'] = (int)$user['id'];

    $pdo = get_pdo();
    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")
        ->execute([':id' => (int)$user['id']]);

    // 使用统一版 audit_log(PDO $pdo, string $action, array $extra, ?string $entityType, ?int $entityId)
    if (function_exists('audit_log')) {
        audit_log(
            $pdo,
            'LOGIN',
            [
                'description' => 'User login',
                'user_id'     => (int)$user['id'],
                'username'    => $user['username'] ?? null,
                'role'        => $user['role'] ?? null,
            ],
            'user',
            (int)$user['id']
        );
    }
}

/** 兼容旧名字 */
function login_user(array $user): void
{
    auth_set_user($user);
}

/**
 * 登出
 */
function auth_logout(): void
{
    $u = current_user();
    if ($u && function_exists('audit_log')) {
        $pdo = get_pdo();
        audit_log(
            $pdo,
            'LOGOUT',
            [
                'description' => 'User logout',
                'user_id'     => (int)$u['id'],
                'username'    => $u['username'] ?? null,
                'role'        => $u['role'] ?? null,
            ],
            'user',
            (int)$u['id']
        );
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    unset($_SESSION['user_id']);
}

/** 兼容旧名字 */
function logout_user(): void
{
    auth_logout();
}

/**
 * 必须登录，否则跳去 login
 */
function require_login(): void
{
    $u = current_user();
    if (!$u) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

/**
 * 当前登录用户绑定的 customer row（仅 CUSTOMER 角色有意义）
 */
function current_customer(): ?array
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $u = current_user();
    if (!$u) return $cached = null;
    $cid = (int)($u['customer_id'] ?? 0);
    if ($cid <= 0) return $cached = null;

    try {
        $pdo = get_pdo();
        $st = $pdo->prepare("SELECT * FROM customers WHERE id = :id LIMIT 1");
        $st->execute([':id' => $cid]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $cached = ($row ?: null);
    } catch (\Throwable $e) {
        return $cached = null;
    }
}

/**
 * CUSTOMER portal: require current user's customer category.
 */
function require_customer_category(int $categoryId): void
{
    require_login();
    $u = current_user();
    if (!$u || ($u['role'] ?? '') !== 'CUSTOMER') {
        http_response_code(403);
        exit('Forbidden');
    }
    $c = current_customer();
    $cat = (int)($c['category_id'] ?? 0);
    if ($cat !== $categoryId) {
        http_response_code(403);
        exit('Forbidden');
    }
}

/**
 * 只允许 internal ADMIN 进入 admin 区
 * （users.role = 'ADMIN'）
 */
function require_admin(): void
{
    $u = current_user();
    if (!$u || $u['role'] !== 'ADMIN') {
        http_response_code(403);
        exit('Forbidden');
    }
}
