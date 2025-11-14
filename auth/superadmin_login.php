<?php
require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/security/session_role.php';

csrf_start();

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    csrf_validate();
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    $db = new MasterDB();
    $stmt = $db->pquery('SELECT id, password FROM users_platform WHERE username=? LIMIT 1','s',[$user]);
    $stmt->bind_result($uid, $hash);
    if($stmt->fetch() && password_verify($pass, $hash)){
        superadmin_login($uid);
        header('Location: /dashboard/dashboard_superadmin.php');
        exit;
    } else {
        $err = 'Usuario o contraseña inválidos';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>SuperAdmin | Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/saira.css">
<link rel="stylesheet" href="/assets/css/main.css">
<style>
body{font-family:Saira,system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#f5f5f5;display:flex;align-items:center;justify-content:center;height:100vh}
.card{background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:28px;max-width:380px;width:100%}
h1{font-size:20px;margin:0 0 16px}
.input{width:100%;padding:12px;border:1px solid #e3e3e3;border-radius:10px;margin-bottom:12px}
.btn{width:100%;padding:12px;border:0;border-radius:12px;background:#663399;color:#fff;font-weight:600;cursor:pointer}
.err{color:#c62828;font-size:14px;margin-bottom:8px}
</style>
</head>
<body>
<div class="card">
  <h1>Panel SuperAdmin</h1>
  <?php if(!empty($err)): ?><div class="err"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?php csrf_input(); ?>
    <input class="input" type="text" name="username" placeholder="Usuario" required>
    <input class="input" type="password" name="password" placeholder="Contraseña" required>
    <button class="btn" type="submit">Ingresar</button>
  </form>
</div>
</body>
</html>
