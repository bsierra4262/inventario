<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// -----------------------------
// DB multiempresa + Logger
// -----------------------------
require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../backend/db.php'; // obtiene $conn del tenant activo

$loggerPath = __DIR__ . '/../logs/logger.php';
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
    header('Location: /auth/login.php');
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
        header('Location: /auth/login.php');
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
$isForced = !empty($_SESSION['force_password_change']);
// El mensaje se guarda como dato y se pinta una sola vez, abajo.
$msg_tipo = '';   // 'danger' | 'success'
$msg_texto = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $posted_token)) {
        log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "CSRF token inválido", "auth");
        $msg_tipo = 'danger'; $msg_texto = 'Operación no autorizada.';
    } else {
        $new_password     = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        $policy_ok = (strlen($new_password) >= 8)
                      && preg_match('/[A-Za-z]/', $new_password)
                      && preg_match('/[0-9]/', $new_password);

        if (!$policy_ok) {
            log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "No cumple política", "auth");
            $msg_tipo = 'danger'; $msg_texto = 'La contraseña debe tener al menos 8 caracteres e incluir letras y números.';
        } elseif ($new_password !== $confirm_password) {
            log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "No coincide confirmación", "auth");
            $msg_tipo = 'danger'; $msg_texto = 'Las contraseñas no coinciden.';
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                // Actualizar contraseña y limpiar flag
                $sql_update = "UPDATE `{$usersTable}` SET password = ?, must_change_password = 0 WHERE id = ?";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param('si', $hashed_password, $user_id);
                $okExec = $stmt_update->execute();
                $affected = $stmt_update->affected_rows;
                $stmt_update->close();

                if (!$okExec || $affected < 1) {
                    log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "UPDATE no afecto filas (affected={$affected}, ok={$okExec})", "auth");
                    $msg_tipo = 'danger'; $msg_texto = 'No se pudo actualizar la contraseña. Probá de nuevo o contactá al administrador.';
                    goto end_post;
                }

                log_event(
                    $user['email'] ?? ("UsuarioID:$user_id"),
                    "CAMBIO CONTRASEÑA",
                    $isForced ? "Contraseña obligatoria configurada" : "Contraseña cambiada correctamente",
                    "auth"
                );

                if ($isForced) {
                    // Modo forzado: limpiar flag de sesión y continuar al inventario
                    unset($_SESSION['force_password_change']);
                    header('Location: /inventario/index.php');
                    exit;
                }

                // Modo voluntario: cerrar sesion preservando company_id (para que el login
                // posterior vaya a la MISMA empresa donde el usuario existe — sin esto el login
                // caia a la company default y no encontraba al usuario)
                $cid_keep = (int)($_SESSION['company_id'] ?? 0);
                $accessToken = $_SESSION['access_token']['access_token'] ?? null;
                if ($accessToken) {
                    @file_get_contents('https://accounts.google.com/o/oauth2/revoke?token=' . urlencode($accessToken));
                }
                // Calcular URL antes de destruir sesion (con subdomain preferido)
                require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
                $loginUrl = tenant_login_url($cid_keep);

                $_SESSION = [];
                if ($cid_keep > 0) $_SESSION['company_id'] = $cid_keep;
                // Antes esto era un alert() del navegador: sale con el nombre
                // del dominio arriba y sin un color de la marca. El aviso lo da
                // la pantalla de acceso, que ya sabe pintarlo (?pass=1).
                $sep = str_contains($loginUrl, '?') ? '&' : '?';
                header('Location: ' . $loginUrl . $sep . 'pass=1');
                exit;

            } catch (Throwable $e) {
                log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "DB error al actualizar: ".$e->getMessage(), "auth");
                $msg_tipo = 'danger'; $msg_texto = 'Error al actualizar la contraseña: ' . $e->getMessage();
            }
            end_post:
        }
    }
}

// La cabecera compartida en vez de un <head> propio: asi esta pantalla recibe
// componentes.css y componentes.js como el resto, y la version de cache de los
// assets, que escrita a mano aca no estaba.
$page_title  = 'Cambiar contraseña';
$page_portal = 'tenant';
require __DIR__ . '/../includes/layout/head.php';
?>
<div class="ih-auth">
  <div class="ih-auth__card ih-anim-fade">
    <div class="ih-auth__header">
      <div class="ih-auth__brand">
        <div class="ih-auth__logo"><?= platform_logo_html() ?></div>
        <div class="ih-auth__brand-text">
          <strong>INTELLIHELP</strong>
          <span>Asset Vault</span>
        </div>
      </div>
      <h2 class="ih-auth__title"><?= $isForced ? 'Contraseña obligatoria' : 'Cambiar contraseña' ?></h2>
      <p class="ih-auth__subtitle"><?= $isForced ? 'Necesitás configurar tu contraseña antes de continuar' : 'Por seguridad, definí una contraseña personal' ?></p>
    </div>

    <div class="ih-auth__body">

      <?php if ($isForced && $msg_texto === ''): ?>
        <div class="ih-alert ih-alert--warning">
          <i class="bi bi-key-fill"></i>
          <div>Tu cuenta requiere una contraseña nueva. Completá el formulario para acceder al inventario.</div>
        </div>
      <?php endif; ?>

      <?php if ($msg_texto !== ''): ?>
        <div class="ih-alert ih-alert--<?= htmlspecialchars($msg_tipo) ?>">
          <i class="bi <?= $msg_tipo === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
          <div><?= htmlspecialchars($msg_texto) ?></div>
        </div>
      <?php endif; ?>

      <form method="POST" action="/auth/change_password.php" autocomplete="off">
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
        <?= $isForced
          ? 'Una vez configurada vas a acceder directo al inventario.'
          : 'Después de cambiarla se cerrará la sesión y deberás volver a ingresar.'
        ?>
      </p>
    </div>

    <div class="ih-auth__footer">
      © <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
