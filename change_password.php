<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// -----------------------------
// DB multiempresa + Logger
// -----------------------------
require_once __DIR__ . '/backend/db.php'; // obtiene $conn del tenant activo

$loggerPath = __DIR__ . '/logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath; // log_event()
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'app') { /* silencioso */ }
    }
}

// -----------------------------
// Guardas de sesión
// -----------------------------
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Nombre real de la tabla de usuarios del tenant (t1_users, t2_users, users, etc.)
$usersTable = tn('users');

// -----------------------------
// Cargar usuario actual (solo para auditoría o reforzar flujos)
// -----------------------------
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    $sql_user = "SELECT id, username, email, password, role FROM `{$usersTable}` WHERE id = ?";
    $stmt_user = $conn->prepare($sql_user);
    $stmt_user->bind_param('i', $user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    $user = $result_user->fetch_assoc();
    $stmt_user->close();

    if (!$user) {
        log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "Usuario no encontrado", "auth");
        header('Location: login.php');
        exit();
    }
} catch (Throwable $e) {
    log_event("UsuarioID:$user_id", "ERROR", "DB error cargando usuario: ".$e->getMessage(), "auth");
    http_response_code(500);
    die('Error de base de datos.');
}

// -----------------------------
// CSRF token
// -----------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// -----------------------------
// POST: Cambiar contraseña
// -----------------------------
$msg_html = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    $posted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $posted_token)) {
        log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "CSRF token inválido", "auth");
        $msg_html = '<div class="alert alert-danger">Operación no autorizada.</div>';
    } else {
        $new_password     = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        // Política mínima: 8+ caracteres, al menos una letra y un número
        $policy_ok = (strlen($new_password) >= 8)
                      && preg_match('/[A-Za-z]/', $new_password)
                      && preg_match('/[0-9]/', $new_password);

        if (!$policy_ok) {
            log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "No cumple política", "auth");
            $msg_html = '<div class="alert alert-danger">La contraseña debe tener al menos 8 caracteres, e incluir letras y números.</div>';
        } elseif ($new_password !== $confirm_password) {
            log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "No coincide confirmación", "auth");
            $msg_html = '<div class="alert alert-danger">Las contraseñas no coinciden.</div>';
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                $sql_update = "UPDATE `{$usersTable}` SET password = ? WHERE id = ?";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param('si', $hashed_password, $user_id);
                $stmt_update->execute();
                $stmt_update->close();

                // Auditoría OK
                log_event(
                    $user['email'] ?? ("UsuarioID:$user_id"),
                    "CAMBIO CONTRASEÑA",
                    "Contraseña cambiada correctamente",
                    "auth"
                );

                // Cerrar sesión COMPLETA (local, multiempresa y Google)
                $accessToken = $_SESSION['access_token']['access_token'] ?? null;
                if ($accessToken) {
                    $revokeUrl = 'https://accounts.google.com/o/oauth2/revoke?token=' . urlencode($accessToken);
                    @file_get_contents($revokeUrl);
                }

                // Limpiar sesión
                $_SESSION = [];
                unset(
                    $_SESSION['company_id'],
                    $_SESSION['tenant'],
                    $_SESSION['tenant_id'],
                    $_SESSION['google_authenticated']
                );
                if (ini_get("session.use_cookies")) {
                    setcookie(session_name(), '', [
                        'expires'  => time() - 42000,
                        'path'     => '/',
                        'domain'   => (str_contains($_SERVER['HTTP_HOST'] ?? '', '.') ? $_SERVER['HTTP_HOST'] : ''),
                        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                }
                session_destroy();

                // Aviso y redirect a login
                echo '<script>
                    alert("Contraseña cambiada exitosamente. Por favor, iniciá sesión nuevamente.");
                    window.location.href = "login.php";
                </script>';
                exit;

            } catch (Throwable $e) {
                log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "DB error al actualizar: ".$e->getMessage(), "auth");
                $msg_html = '<div class="alert alert-danger">Error al actualizar la contraseña.</div>';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#663399">
  <title>Cambiar contraseña · Asset Vault · INTELLIHELP</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/assets/css/intellihelp.css" rel="stylesheet">
</head>
<body>
<div class="ih-auth">
  <div class="ih-auth__card ih-anim-fade">
    <div class="ih-auth__header">
      <div class="ih-auth__brand">
        <div class="ih-auth__logo">i</div>
        <div class="ih-auth__brand-text">
          <strong>INTELLIHELP</strong>
          <span>Asset Vault</span>
        </div>
      </div>
      <h2 class="ih-auth__title">Cambiar contraseña</h2>
      <p class="ih-auth__subtitle">Por seguridad, definí una contraseña personal</p>
    </div>

    <div class="ih-auth__body">

      <?php
        if (!empty($msg_html)) {
          // Adaptar HTML legacy de alertas a estilo ih-alert
          $clean = str_replace(
            ['<div class="alert alert-danger">', '<div class="alert alert-success">'],
            ['<div class="ih-alert ih-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i><div>', '<div class="ih-alert ih-alert--success"><i class="bi bi-check-circle-fill"></i><div>'],
            $msg_html
          );
          $clean = str_replace('</div>', '</div></div>', $clean);
          echo $clean;
        }
      ?>

      <form method="POST" action="change_password.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <div class="ih-field">
          <label class="ih-field__label" for="new_password">Nueva contraseña</label>
          <input class="ih-input" type="password" id="new_password" name="new_password" required minlength="8"
                 pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="Mínimo 8 caracteres con letras y números">
          <div class="ih-field__hint">Mínimo 8 caracteres, debe incluir letras y números.</div>
        </div>

        <div class="ih-field">
          <label class="ih-field__label" for="confirm_password">Confirmar contraseña</label>
          <input class="ih-input" type="password" id="confirm_password" name="confirm_password" required minlength="8"
                 pattern="(?=.*[A-Za-z])(?=.*\d).{8,}">
        </div>

        <button class="ih-btn ih-btn--primary ih-btn--lg ih-btn--block" type="submit">
          <i class="bi bi-shield-check"></i> Cambiar contraseña
        </button>
      </form>

      <p class="ih-text-xs ih-text-muted ih-text-center ih-mt-4">
        Después de cambiarla se cerrará la sesión y deberás volver a ingresar.
      </p>
    </div>

    <div class="ih-auth__footer">
      © <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault
    </div>
  </div>
</div>
</body>
</html>
