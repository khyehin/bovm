// public/admin/assets/js/admin-actions-menu.js
// 负责：三粒点 actions-menu + data-confirm 提示

document.addEventListener('DOMContentLoaded', function () {

  // ---------- 三粒点下拉 ----------
  document.addEventListener('click', function (e) {
    const trigger = e.target.closest('.actions-menu-trigger');
    const menu    = e.target.closest('.actions-menu');

    // 先关闭所有已打开的菜单
    document.querySelectorAll('.actions-menu.is-open').forEach(function (m) {
      if (m !== menu) {
        m.classList.remove('is-open');
      }
    });

    // 点到三粒点
    if (trigger && menu) {
      e.preventDefault();
      e.stopPropagation();
      menu.classList.toggle('is-open'); // 切换 is-open
      return;
    }

    // 如果点到的是菜单内部的其他元素，就不要马上关掉
    if (e.target.closest('.actions-menu')) {
      return;
    }

    // 点击页面其它地方 → 关闭所有菜单
    document.querySelectorAll('.actions-menu.is-open').forEach(function (m) {
      m.classList.remove('is-open');
    });
  });

  // ---------- data-confirm （删除 / toggle 提示） ----------
  document.addEventListener('click', function (e) {
    const link = e.target.closest('a[data-confirm]');
    if (!link) return;

    const msg = link.getAttribute('data-confirm');
    if (!msg) return;

    if (!window.confirm(msg)) {
      e.preventDefault();
      e.stopPropagation();
    }
  });
});
