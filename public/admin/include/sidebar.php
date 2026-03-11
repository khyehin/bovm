<?php
// public/admin/include/sidebar.php
declare(strict_types=1);

$currentPath = (string)($_SERVER['SCRIPT_NAME'] ?? '');

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('nav_active')) {
    function nav_active(string $needle, string $currentPath): string
    {
        return (strpos($currentPath, $needle) !== false) ? ' active' : '';
    }
}

$hasT = function_exists('t');
?>
<aside class="admin-sidebar">
  <nav class="sidebar-menu">

    <!-- OVERVIEW -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">
        <?= h($hasT ? t('admin.nav.section.overview', [], 'OVERVIEW') : 'OVERVIEW') ?>
      </div>
      <a href="<?= h(url('admin/dashboard/index.php')) ?>"
         class="sidebar-link<?= nav_active('/admin/dashboard/index.php', $currentPath) ?>">
        <?= h($hasT ? t('admin.nav.dashboard', [], 'Dashboard') : 'Dashboard') ?>
      </a>
    </div>

    <!-- REPORTS -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">
        <?= h($hasT ? t('admin.nav.section.reports', [], 'REPORTS') : 'REPORTS') ?>
      </div>
      <a href="<?= h(url('admin/reports/transaction_report.php')) ?>"
         class="sidebar-link<?= nav_active('/admin/reports/transaction_report.php', $currentPath) ?>">
        <?= h($hasT ? t('admin.nav.transaction_report', [], 'Transaction Report') : 'Transaction Report') ?>
      </a>
      <a href="<?= h(url('admin/reports/customer_list_report.php')) ?>"
         class="sidebar-link<?= nav_active('/admin/reports/customer_list_report.php', $currentPath) ?>">
        <?= h($hasT ? t('admin.nav.customer_report', [], 'Customer Report') : 'Customer Report') ?>
      </a>
    </div>

    <!-- CUSTOMERS -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">
        <?= h($hasT ? t('admin.nav.section.customers', [], 'CUSTOMERS') : 'CUSTOMERS') ?>
      </div>
      <a href="<?= h(url('admin/customers/list.php')) ?>"
         class="sidebar-link<?= nav_active('/admin/customers/list.php', $currentPath) ?>">
        <?= h($hasT ? t('admin.nav.customer_list', [], 'Customer List') : 'Customer List') ?>
      </a>
    </div>

    <!-- BANK (新模块) -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">
        <?= h($hasT ? t('admin.nav.section.bank', [], 'BANK') : 'BANK') ?>
      </div>

      <!-- Bank Accounts -->
      <a href="<?= h(url('admin/bank/accounts.php')) ?>"
         class="sidebar-link<?= nav_active('/admin/bank/accounts.php', $currentPath) ?>">
        <?= h($hasT ? t('admin.nav.bank.accounts', [], 'Bank Accounts') : 'Bank Accounts') ?>
      </a>

      <!-- Bank Transactions (全银行合并视图) -->
      <a href="<?= h(url('admin/bank/transactions_all.php')) ?>"
         class="sidebar-link<?= nav_active('/admin/bank/transactions_all.php', $currentPath) ?>">
        <?= h($hasT ? t('admin.nav.bank.transactions', [], 'Bank Transactions') : 'Bank Transactions') ?>
      </a>

    <!-- PAYERS -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">
        <?= h($hasT ? t('admin.nav.section.payers', [], 'PAYERS') : 'PAYERS') ?>
      </div>
      <a href="<?= h(url('admin/payers/payer_company_list.php')) ?>"
         class="sidebar-link<?= nav_active('/admin/payers/payer_company_list.php', $currentPath) ?>">
        <?= h($hasT ? t('admin.nav.payer_companies', [], 'Payer Companies') : 'Payer Companies') ?>
      </a>
      <a href="<?= h(url('admin/payers/payer_staff_list.php')) ?>"
         class="sidebar-link<?= nav_active('/admin/payers/payer_staff_list.php', $currentPath) ?>">
        <?= h($hasT ? t('admin.nav.payer_staff', [], 'Payer Staff') : 'Payer Staff') ?>
      </a>
    </div>

    <!-- SECURITY -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">
        <?= h($hasT ? t('admin.nav.section.security', [], 'SECURITY') : 'SECURITY') ?>
      </div>
      <a href="<?= h(url('admin/users/list.php')) ?>"
         class="sidebar-link<?= nav_active('/admin/users/list.php', $currentPath) ?>">
        <?= h($hasT ? t('admin.nav.users', [], 'Users') : 'Users') ?>
      </a>
      <a href="<?= h(url('admin/roles/list.php')) ?>"
         class="sidebar-link<?= nav_active('/admin/roles/list.php', $currentPath) ?>">
        <?= h($hasT ? t('admin.nav.roles', [], 'Roles & Permissions') : 'Roles & Permissions') ?>
      </a>
      <a href="<?= h(url('admin/audit_logs/log_list.php')) ?>"
         class="sidebar-link<?= nav_active('/admin/audit_logs/log_list.php', $currentPath) ?>">
        <?= h($hasT ? t('admin.nav.audit_logs', [], 'Audit Logs') : 'Audit Logs') ?>
      </a>
    </div>

  </nav>
</aside>
