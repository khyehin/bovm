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

    // 初始化：隐藏所有 actions dropdown
    document.querySelectorAll('.actions-menu .actions-menu-dropdown').forEach(function (dd) {
        dd.style.display = 'none';
    });

    // Actions dropdown（和 admin 行为类似，但直接控制 display）
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.actions-menu-trigger');
        var menu    = e.target.closest('.actions-menu');

        // 先关闭所有已打开的菜单
        document.querySelectorAll('.actions-menu .actions-menu-dropdown').forEach(function (dd) {
            dd.style.display = 'none';
        });

        // 点到三粒点
        if (trigger && menu) {
            e.preventDefault();
            e.stopPropagation();
            var dropdown = menu.querySelector('.actions-menu-dropdown');
            if (dropdown) {
                dropdown.style.display = 'block';
            }
            return;
        }

        // 如果点到的是菜单内部的其他元素，就不要马上关掉
        if (e.target.closest('.actions-menu')) {
            return;
        }

        // 点击页面其它地方 → 关闭所有菜单（上面已经关过一次，这里兜底）
        document.querySelectorAll('.actions-menu .actions-menu-dropdown').forEach(function (dd) {
            dd.style.display = 'none';
        });
    });
});
</script>

</body>
</html>
