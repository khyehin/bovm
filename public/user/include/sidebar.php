<?php
// public/user/include/sidebar.php
declare(strict_types=1);

if (!function_exists('current_user')) {
    require_once __DIR__ . '/../../../config/bootstrap.php';
}

$u = current_user();
if (($u['role'] ?? '') !== 'CUSTOMER') {
    http_response_code(403);
    exit('Forbidden');
}

$cust = function_exists('current_customer') ? current_customer() : null;
$custCat = (int)($cust['category_id'] ?? 0);

$active_nav = $active_nav ?? 'dashboard';

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
?>
<aside class="admin-sidebar">
  <div class="sidebar-menu">

    <!-- 顶部用户信息 -->
    <div class="sidebar-section" style="margin-bottom: 22px;">
      <div class="sidebar-section-title">
        <?= h(t('portal.header.signed_in_as', [], 'Signed in as')) ?>
      </div>
      <div style="font-size:13px;font-weight:600;margin-bottom:4px;">
        <?= h($u['full_name'] ?? $u['username'] ?? 'Customer') ?>
      </div>
    </div>

    <!-- Main -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">
        <?= h(t('portal.sidebar.section_main', [], 'Main')) ?>
      </div>

      <a href="<?= h(url('user/dashboard/index.php')) ?>"
         class="sidebar-link <?= $active_nav === 'dashboard' ? 'active' : '' ?>">
        <?= h(t('portal.sidebar.dashboard', [], 'Dashboard')) ?>
      </a>

      <a href="<?= h(url('user/txn/txns.php')) ?>"
         class="sidebar-link <?= $active_nav === 'txns' ? 'active' : '' ?>">
        <?= h(t('portal.sidebar.txns', [], 'Transactions / Reports')) ?>
      </a>

      <a href="<?= h(url('user/txn/invoices.php')) ?>"
         class="sidebar-link <?= $active_nav === 'invoices' ? 'active' : '' ?>">
        <?= h(t('portal.sidebar.invoices', [], 'Invoices / Quotations')) ?>
      </a>

      <?php if ($custCat === 1): ?>
      <a href="<?= h(url('user/company1/customers.php')) ?>"
         class="sidebar-link <?= $active_nav === 'company1_customers' ? 'active' : '' ?>">
        Company1 · Customers
      </a>
      <a href="<?= h(url('user/company1/invoices.php')) ?>"
         class="sidebar-link <?= $active_nav === 'company1_invoices' ? 'active' : '' ?>">
        Company1 · Invoices
      </a>
      <?php endif; ?>
    </div>

    <!-- Settings -->
    <div class="sidebar-section">
      <div class="sidebar-section-title">
        <?= h(t('portal.sidebar.section_settings', [], 'Settings')) ?>
      </div>

      <a href="<?= h(url('user/users/users.php')) ?>"
         class="sidebar-link <?= $active_nav === 'users' ? 'active' : '' ?>">
        <?= h(t('portal.sidebar.users', [], 'Login users')) ?>
      </a>
    </div>

  </div>
</aside>
