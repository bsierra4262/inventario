<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/security/session_role.php';

csrf_start();

// Si ya está autenticado, redirigir directamente
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

$isFirstRun     = false;
$bootstrapError = '';

// Auto-bootstrap: crea users_platform si no existe y seed bsierra si está vacía
try {
    $db = new MasterDB();

    $db->conn->query("CREATE TABLE IF NOT EXISTS `users_platform` (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        username    VARCHAR(100) NOT NULL,
        password    VARCHAR(255) NOT NULL,
        email       VARCHAR(255) DEFAULT NULL,
        role        ENUM('superadmin','support') NOT NULL DEFAULT 'superadmin',
        active      TINYINT(1)  NOT NULL DEFAULT 1,
        created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $cntRow = $db->conn->query("SELECT COUNT(*) as c FROM users_platform")->fetch_assoc();
    if ((int)($cntRow['c'] ?? 0) === 0) {
        $isFirstRun  = true;
        $saUser      = 'bsierra';
        $saHash      = password_hash('***SECRETO-PURGADO***', PASSWORD_DEFAULT);
        $saEmail     = 'bsierra4262@gmail.com';
        $saStmt      = $db->conn->prepare("INSERT INTO users_platform (username, password, email, role) VALUES (?, ?, ?, 'superadmin')");
        $saStmt->bind_param('sss', $saUser, $saHash, $saEmail);
        $saStmt->execute();
        $saStmt->close();
    }
} catch (Throwable $be) {
    $bootstrapError = $be->getMessage();
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $user = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');

    try {
        if (!isset($db)) { $db = new MasterDB(); }
        $stmt = $db->pquery(
            'SELECT id, password FROM users_platform WHERE username = ? AND active = 1 LIMIT 1',
            's',
            [$user]
        );
        $stmt->bind_result($uid, $hash);
        if ($stmt->fetch() && password_verify($pass, $hash)) {
            $stmt->close();
            superadmin_login($uid);
            header('Location: /dashboard/dashboard_superadmin.php');
            exit;
        }
        $stmt->close();
        $err = 'Usuario o contraseña inválidos.';
    } catch (Throwable $ex) {
        $err = 'Error del sistema: ' . htmlspecialchars($ex->getMessage());
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>SuperAdmin — Asset Vault</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { font-family: 'Saira', system-ui, sans-serif; background: #f0ecf7; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { border: none; border-radius: 18px; box-shadow: 0 12px 40px rgba(102,51,153,.13); max-width: 400px; width: 100%; }
  .card-header { background: #663399; color: #fff; border-radius: 18px 18px 0 0 !important; padding: 22px 28px 16px; }
  .card-header h4 { margin: 0; font-weight: 700; letter-spacing: -.3px; }
  .card-header small { opacity: .75; font-size: 13px; }
  .card-body { padding: 28px; }
  .form-control, .form-control:focus { border-radius: 10px; border-color: #d0bfea; box-shadow: none; }
  .form-control:focus { border-color: #663399; box-shadow: 0 0 0 .2rem rgba(102,51,153,.15); }
  .btn-primary { background: #663399; border-color: #663399; border-radius: 12px; font-weight: 600; padding: 11px; }
  .btn-primary:hover { background: #4E247A; border-color: #4E247A; }
  .alert-firstrun { background: #fff3e0; border-left: 4px solid #f57c00; border-radius: 10px; }
  .badge-pass { background: #263238; color: #fff; font-family: monospace; font-size: 14px; padding: 4px 10px; border-radius: 6px; }
</style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <h4>Panel SuperAdmin</h4>
    <small>Asset Vault — Gestión de clientes</small>
  </div>
  <div class="card-body">

    <?php if ($bootstrapError): ?>
      <div class="alert alert-danger" style="border-radius:10px;font-size:13px;">
        Error de infraestructura: <?php echo htmlspecialchars($bootstrapError); ?>
      </div>
    <?php endif; ?>

    <?php if ($isFirstRun): ?>
      <div class="alert alert-firstrun p-3 mb-3">
        <strong>Primera ejecución detectada.</strong><br>
        Se creó el superadmin con las siguientes credenciales:<br>
        Usuario: <span class="badge-pass">bsierra</span><br>
        Contraseña: <span class="badge-pass">***SECRETO-PURGADO***</span><br>
        <small class="text-muted">Cambiá la contraseña después de ingresar.</small>
      </div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="alert alert-danger" style="border-radius:10px;"><?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <?php csrf_input(); ?>
      <div class="mb-3">
        <label class="form-label fw-500" for="username">Usuario</label>
        <input id="username" class="form-control" type="text" name="username" placeholder="bsierra" required autofocus>
      </div>
      <div class="mb-4">
        <label class="form-label fw-500" for="password">Contraseña</label>
        <input id="password" class="form-control" type="password" name="password" required>
      </div>
      <button class="btn btn-primary w-100" type="submit">Ingresar</button>
    </form>
  </div>
</div>
</body>
</html>
