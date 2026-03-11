<?php
// public/index.php  — 登录页（统一入口）
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string
  {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($username === '' || $password === '') {
    $error = 'Invalid username or password';
  } else {
    // 从 users 表查账号
    $st = $pdo->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
    $st->execute([':u' => $username]);
    $user = $st->fetch();

    // 兼容旧明文 + 新 password_hash
    $ok = false;
    if ($user) {
      $hash = (string)$user['password_hash'];

      if (password_verify($password, $hash)) {
        $ok = true;
      } elseif ($password === $hash) {
        // 之前明文存进 password_hash，也允许登录
        $ok = true;
      }
    }

    if (!$user || !$ok) {
      $error = 'Invalid username or password';
    } elseif ((int)$user['is_active'] !== 1) {
      $error = 'Your account is inactive';
    } else {
      // ✅ 登录成功
      login_user($user);

      // 更新 last_login_at
      $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")
        ->execute([':id' => (int)$user['id']]);

      // 首次登录必须换密码
      if ((int)$user['must_change_password'] === 1) {
        header('Location: ' . url('change_password.php'));
        exit;
      }

      // ✅ 根据 role 分流
      $role = (string)($user['role'] ?? '');

      if ($role === 'ADMIN') {
        // 管理后台首页
        header('Location: ' . url('admin/dashboard/index.php'));
      } elseif ($role === 'CUSTOMER') {
        // 客户登录 ➜ Customer Portal
        header('Location: ' . url('user/dashboard/index.php'));
      } else {
        // 其它角色（如果以后有）：先当作 CUSTOMER，用 customer portal
        header('Location: ' . url('user/dashboard/index.php'));
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
  <title>Login - bo.vm</title>

  <link rel="stylesheet" href="<?= h(url('admin/assets/style.css')) ?>">
  <!-- Favicon -->
  <link rel="icon" type="image/png" sizes="96x96"
    href="<?= h(url('assets/img/favicon-96.png')) ?>">

  <link rel="apple-touch-icon"
    href="<?= h(url('assets/img/apple-touch-icon.png')) ?>">

  <!-- PWA / Manifest（可选） -->
  <link rel="manifest"
    href="<?= h(url('assets/img/site.webmanifest')) ?>">


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
      padding: 30px 34px;
      width: 380px;
      border-radius: 12px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
    }
  </style>
</head>

<body class="login-body">

  <div class="login-box">

    <h1 class="card-title" style="text-align:center; margin-bottom:8px;">
      bo.vm
    </h1>

    <h2 class="card-title" style="text-align:center; font-size:18px; margin-bottom:20px;">
      Login
    </h2>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <div class="form-group">
        <label for="username">Username</label>
        <input
          type="text"
          id="username"
          name="username"
          class="form-control"
          required
          autofocus
          autocomplete="username"
          value="<?= h($_POST['username'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input
          type="password"
          id="password"
          name="password"
          class="form-control"
          required
          autocomplete="current-password">
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%; margin-top:10px;">
        Login
      </button>
    </form>

  </div>

</body>

</html>