<?php
// public/user/include/header.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_login();

$u = current_user();
$page_title  = $page_title ?? t('portal.header.app_title', [], 'Customer Portal');
$active_nav  = $active_nav ?? '';

if (!function_exists('h')) {
  function h($v): string
  {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

/* ---------- 语言处理：先看 ?lang=，再存进 session ---------- */
$allowedLangs = ['en', 'ms', 'zh'];

if (isset($_GET['lang']) && in_array($_GET['lang'], $allowedLangs, true)) {
  $_SESSION['lang'] = $_GET['lang'];
}

$currentLang = $_SESSION['lang'] ?? 'en';

/* 按钮上显示的文字（走翻译） */
$langLabelMap = [
  'en' => t('portal.lang.en', [], 'English'),
  'ms' => t('portal.lang.ms', [], 'Malay'),
  'zh' => t('portal.lang.zh', [], '中文'),
];

$displayLang = $langLabelMap[$currentLang] ?? 'English';
?>
<!DOCTYPE html>
<html lang="<?= h($currentLang) ?>">

<head>
  <meta charset="UTF-8">
  <title>
    <?= h($page_title) ?> - <?= h(t('portal.header.app_title', [], 'Customer Portal')) ?>
  </title>

  <!-- Favicon -->
  <link rel="icon" type="image/png" sizes="96x96"
    href="<?= h(url('assets/img/favicon-96.png')) ?>">

  <link rel="apple-touch-icon"
    href="<?= h(url('assets/img/apple-touch-icon.png')) ?>">

  <!-- PWA / Manifest（可选） -->
  <link rel="manifest"
    href="<?= h(url('assets/img/site.webmanifest')) ?>">

  <!-- Customer portal 自己的 CSS（里面有 @import admin 的） -->
  <link rel="stylesheet" href="<?= h(url('user/assets/style.css')) ?>">
</head>

<body class="admin-body">

  <header class="admin-topbar">
    <div class="topbar-left">
      <!-- 三条线按钮 -->
      <button id="sidebarToggle"
        class="topbar-toggle"
        type="button"
        aria-label="Toggle sidebar">☰</button>

      <span class="topbar-logo">bo.vm</span>
      <span class="topbar-divider"></span>

      <!-- 公司名 -->
      <span class="topbar-app-title">
        <?= h($u['company_name'] ?? 'My Company') ?>
      </span>
    </div>

    <div class="topbar-right">
      <!-- 当前语言按钮 -->
      <div class="lang-wrap">
        <button id="cpLangToggle" class="btn-topbar">
          <?= h($displayLang) ?> ▾
        </button>
        <div id="cpLangMenu" class="lang-dropdown">
          <a href="<?= h(current_url_with_lang('en')) ?>">EN</a>
          <a href="<?= h(current_url_with_lang('zh')) ?>">中</a>
          <a href="<?= h(current_url_with_lang('ms')) ?>">BM</a>
        </div>
      </div>

      <!-- Logout -->
      <a href="<?= h(url('logout.php')) ?>" class="btn-topbar">
        <?= h(t('portal.header.logout', [], 'Log out')) ?>
      </a>
    </div>
  </header>

  <div class="admin-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="admin-main">
      <div class="admin-main-inner">