<?php
// app/helpers.php
declare(strict_types=1);

/**
 * HTML 转义助手
 *
 * 全站建议都用这个 h()（如果某些文件自己定义了 h()，因为有 function_exists 判
 * 断，不会报错）。
 */
if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * 生成相对项目根的 URL
 *
 * 例：
 *   url('admin/customers/list.php')
 *   url('/user/dashboard/index.php')
 */
if (!function_exists('url')) {
    function url(string $path = ''): string {
        $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        $path = ltrim($path, '/');
        if ($path === '') {
            return $base ?: '/';
        }
        return ($base ?: '') . '/' . $path;
    }
}

/**
 * 简单重定向到某个路径（用 url() 包装）
 *
 * 例：
 *   redirect('admin/login.php');
 *   redirect('/user/dashboard/index.php');
 */
if (!function_exists('redirect')) {
    function redirect(string $path): void {
        header('Location: ' . url($path));
        exit;
    }
}
