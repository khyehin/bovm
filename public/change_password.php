<?php
// public/change_password.php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

require_login();
$u = current_user();

// ⭐ FIX：初始化 PDO
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old     = $_POST['old_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($new !== $confirm) {
        $error = t('auth.password_mismatch', [], 'New password and confirmation do not match');
    } else {

        // 取得旧密码 hash
        $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
        $st->execute([':id' => $u['id']]);
        $row = $st->fetch();

        if (!$row || !password_verify($old, $row['password_hash'])) {
            $error = t('auth.old_password_wrong', [], 'Current password is incorrect');
        } else {

            // 更新密码
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("
                UPDATE users
                SET password_hash = :p,
                    must_change_password = 0,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $upd->execute([
                ':p' => $hash,
                ':id' => $u['id']
            ]);

            // 更新 session
            $_SESSION['user']['must_change_password'] = 0;

            // 跳 admin 或 user dashboard
            if ($u['role'] === 'ADMIN') {
                header('Location: admin/dashboard/index.php');
            } else {
                header('Location: user/dashboard/index.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title><?= h(t('auth.change_password.title', [], 'Change Password')) ?> - <?= h(t('app.name', [], 'bo.vm')) ?></title>
  <link rel="stylesheet" href="<?= h(url('admin/assets/style.css')) ?>">

  <style>
    body.login-body {
      height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0;
      background: #eef1f6;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .login-box {
      background: #ffffff;
      padding: 28px 32px;
      width: 380px;
      border-radius: 12px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.08);
    }
  </style>
</head>

<body class="login-body">

  <div class="login-box">

    <h1 class="card-title" style="text-align:center; margin-bottom:18px;">
      <?= h(t('auth.change_password.title', [], 'Change Password')) ?>
    </h1>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="form-group">
        <label for="old_password"><?= h(t('auth.old_password', [], 'Current Password')) ?></label>
        <input type="password" id="old_password" name="old_password" class="form-control" required>
      </div>

      <div class="form-group">
        <label for="new_password"><?= h(t('auth.new_password', [], 'New Password')) ?></label>
        <input type="password" id="new_password" name="new_password" class="form-control" required>
      </div>

      <div class="form-group">
        <label for="confirm_password"><?= h(t('auth.confirm_password', [], 'Confirm New Password')) ?></label>
        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%; margin-top:8px;">
        <?= h(t('common.save', [], 'Save')) ?>
      </button>
    </form>
  </div>

</body>
</html>
