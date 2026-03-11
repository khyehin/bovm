<?php
// public/admin/include/header.php
declare(strict_types=1);

$u = current_user();
$page_title = $page_title ?? 'Admin';

if (!function_exists('h')) {
  function h($v): string
  {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

/* ---------- 语言处理：与 user portal 一样 ---------- */
$allowedLangs = ['en', 'ms', 'zh'];

if (isset($_GET['lang']) && in_array($_GET['lang'], $allowedLangs, true)) {
  $_SESSION['lang'] = $_GET['lang'];
}

$currentLang = $_SESSION['lang'] ?? 'en';

$langLabelMap = [
  'en' => 'English',
  'ms' => 'Malay',
  'zh' => '中文',
];

$displayLang = $langLabelMap[$currentLang] ?? 'English';

// 顶部 app title 也走多语言（可选）
$appTitle = function_exists('t')
  ? t('admin.header.app_title', [], 'Admin')
  : 'Admin';
?>
<!DOCTYPE html>
<html lang="<?= h($currentLang) ?>">

<head>
  <meta charset="UTF-8">
  <title>bo.vm <?= h($appTitle) ?> - <?= h($page_title) ?></title>

  <!-- admin theme -->
  <link rel="stylesheet" href="<?= h(url('admin/assets/style.css')) ?>">
  <link rel="stylesheet" href="<?= h(url('admin/assets/css/admin-actions-menu.css')) ?>">
  <link rel="stylesheet" href="<?= h(url('public/assets/css/date_range.css')) ?>">

  <!-- Favicon -->
  <link rel="icon" type="image/png" sizes="96x96"
    href="<?= h(url('assets/img/favicon-96.png')) ?>">

  <link rel="apple-touch-icon"
    href="<?= h(url('assets/img/apple-touch-icon.png')) ?>">

  <!-- PWA / Manifest（可选） -->
  <link rel="manifest"
    href="<?= h(url('assets/img/site.webmanifest')) ?>">


  <!-- admin own css （加入语言 dropdown 样式） -->
  <style>
    .lang-wrap {
      position: relative;
    }

    .lang-dropdown {
      display: none;
      position: absolute;
      right: 0;
      top: 100%;
      margin-top: 6px;
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
      min-width: 130px;
      z-index: 50;
    }

    .lang-dropdown a {
      display: block;
      padding: 8px 12px;
      font-size: 13px;
      text-decoration: none;
      color: #111827;
    }

    .lang-dropdown a:hover {
      background: #f3f4f6;
    }
  </style>
</head>

<body class="admin-body">

  <header class="admin-topbar">
    <div class="topbar-left">

      <!-- 三条线按钮 -->
      <button id="sidebarToggle"
        class="topbar-toggle"
        type="button"
        aria-label="<?= h(function_exists('t') ? t('admin.header.toggle_sidebar', [], 'Toggle sidebar') : 'Toggle sidebar') ?>">☰</button>

      <span class="topbar-logo">bo.vm</span>
      <span class="topbar-divider"></span>
      <span class="topbar-app-title"><?= h($page_title) ?></span>
    </div>

    <div class="topbar-right">

      <span class="topbar-user">
        <?= h($u['full_name'] ?? $u['username']) ?> (<?= h($u['role']) ?>)
      </span>

      <!-- Language Dropdown -->
      <div class="lang-wrap">
        <button id="adminLangToggle" class="btn-topbar">
          <?= h($displayLang) ?> ▾
        </button>
        <div id="adminLangMenu" class="lang-dropdown">
          <a href="<?= h(current_url_with_lang('en')) ?>">EN</a>
          <a href="<?= h(current_url_with_lang('zh')) ?>">中</a>
          <a href="<?= h(current_url_with_lang('ms')) ?>">BM</a>
        </div>
      </div>

      <a class="btn-topbar" href="<?= h(url('logout.php')) ?>">
        <?= h(function_exists('t') ? t('admin.header.logout', [], 'Logout') : 'Logout') ?>
      </a>
    </div>
  </header>

  <div class="admin-shell">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="admin-main">
      <div class="admin-main-inner">