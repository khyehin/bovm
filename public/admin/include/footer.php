<?php
// public/admin/include/footer.php
?>
    </div><!-- /.admin-main-inner -->
  </main><!-- /.admin-main -->
</div><!-- /.admin-shell -->

<footer class="admin-footer">
  <div class="admin-footer-inner">
    © <?= date('Y') ?> bo.vm ·
    <?php
      $rights = function_exists('t')
          ? t('admin.footer.rights', [], 'All rights reserved.')
          : 'All rights reserved.';
      echo htmlspecialchars($rights, ENT_QUOTES, 'UTF-8');
    ?>
  </div>
</footer>

<!-- ========== 这些是必须的 JS（一定要有） ========== -->

<script src="<?= h(url('admin/assets/js/admin-actions-menu.js')) ?>"></script>
<script src="<?= h(url('assets/date_range.js')) ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /* ---------- Sidebar toggle ---------- */
    const btn = document.getElementById('sidebarToggle');
    if (btn) {
        btn.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-collapsed');
        });
    }

    /* ---------- Language dropdown (admin topbar) ---------- */
    const langToggle = document.getElementById('adminLangToggle');
    const langMenu   = document.getElementById('adminLangMenu');

    if (langToggle && langMenu) {
        langToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = langMenu.style.display === 'block';
            langMenu.style.display = isOpen ? 'none' : 'block';
        });

        document.addEventListener('click', function () {
            langMenu.style.display = 'none';
        });
    }
});
</script>

</body>
</html>
