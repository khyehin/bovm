<?php
// config/i18n.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * 支持哪些语言
 * en = English
 * zh = 简体中文
 * ms = Bahasa Melayu
 */
$allowedLangs = ['en', 'zh', 'ms'];

/**
 * 语言切换：当页即时生效
 * 通过 ?lang=en / ?lang=zh / ?lang=ms 切换
 */
if (isset($_GET['lang']) && in_array($_GET['lang'], $allowedLangs, true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

/**
 * 当前语言（默认 en）
 */
$lang = $_SESSION['lang'] ?? 'en';
if (!in_array($lang, $allowedLangs, true)) {
    $lang = 'en';
}

/**
 * 载入语言文件：lang/{lang}.php
 */
$__translations = [];
$path = __DIR__ . '/../lang/' . $lang . '.php';

if (file_exists($path)) {
    $__translations = require $path;
}

/**
 * 从数组中找 key（含 dot notation）
 *
 * 例：
 *   __tr_find($arr, 'admin.dashboard.title')
 */
function __tr_find(array $arr, string $key): mixed
{
    // 先试试直接 key
    if (isset($arr[$key])) {
        return $arr[$key];
    }

    // 支持 a.b.c 形式
    if (strpos($key, '.') !== false) {
        $parts = explode('.', $key);
        $cur   = $arr;

        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return null;
            }
            $cur = $cur[$p];
        }
        return $cur;
    }

    return null;
}

/**
 * 主翻译函数
 *
 * 用法：
 *   t('admin.dashboard.title')
 *   t('admin.dashboard.subtitle', ['name' => 'Khye'])
 *   t('some.key', 'Fallback text')
 */
function t(string $key, array|string $vars = [], string $fallback = ''): string
{
    global $__translations;

    // 兼容旧写法：t('key', 'Fallback')
    if (is_string($vars)) {
        $fallback = $vars;
        $vars     = [];
    }

    $text = $fallback;

    $found = __tr_find($__translations, $key);
    if (is_string($found)) {
        $text = $found;
    }

    if ($text === '') {
        $text = $fallback !== '' ? $fallback : $key;
    }

    // 简单占位符替换：{name} => $vars['name']
    if (!empty($vars)) {
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string)$v, $text);
        }
    }

    return $text;
}

/**
 * 获取当前语言代码（en / zh / ms）
 */
if (!function_exists('current_lang')) {
    function current_lang(): string
    {
        // 使用 global $lang（上面已处理）
        global $lang;
        return $lang ?? 'en';
    }
}

/**
 * 生成当前页面的 URL，并替换 / 设置 lang 参数
 *
 * 用在 Header 的语言切换：
 *   <a href="<?= h(current_url_with_lang('en')) ?>">EN</a>
 *   <a href="<?= h(current_url_with_lang('zh')) ?>">中</a>
 *   <a href="<?= h(current_url_with_lang('ms')) ?>">BM</a>
 */
if (!function_exists('current_url_with_lang')) {
    function current_url_with_lang(string $newLang): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        $parts = parse_url($uri);
        $path  = $parts['path']  ?? '';
        $query = $parts['query'] ?? '';

        $params = [];
        if ($query !== '') {
            parse_str($query, $params);
        }

        // 替换 / 新增 lang 参数
        $params['lang'] = $newLang;

        $qs = http_build_query($params);

        if ($qs === '') {
            return $path;
        }

        return $path . '?' . $qs;
    }
}
