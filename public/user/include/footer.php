<?php
// public/user/include/footer.php
declare(strict_types=1);
?>
      </div><!-- /.admin-main-inner -->
    </main><!-- .admin-main -->
</div><!-- .admin-shell -->

<footer class="admin-footer">
  <div class="admin-footer-inner">
    © <?= date('Y') ?> bo.vm ·
    <?= htmlspecialchars(
        t('portal.footer.powered_by', [], 'Powered by Vision Mix'),
        ENT_QUOTES,
        'UTF-8'
    ) ?>
  </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // Sidebar collapse
    var btn = document.getElementById('sidebarToggle');
    if (btn) {
        btn.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-collapsed');
        });
    }

    // Language dropdown
    var t = document.getElementById('cpLangToggle');
    var m = document.getElementById('cpLangMenu');

    if (t && m) {
        t.addEventListener('click', function (e) {
            e.stopPropagation();
            m.style.display = (m.style.display === 'block') ? 'none' : 'block';
        });
        document.addEventListener('click', function () {
            m.style.display = 'none';
        });
    }
});
</script>

</body>
</html>
