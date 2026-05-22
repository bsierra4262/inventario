<?php
/**
 * bay.php — Herramienta de RESET del tenant (DESARROLLO)
 *
 * ATENCIÓN: Borra TODAS las tablas del tenant activo. Es destructivo.
 * Por seguridad solo se permite ejecutar bajo cualquiera de estas condiciones:
 *
 *   1) Variable de entorno  ASSET_VAULT_DEV=1
 *   2) Host coincide con localhost / *.local / desarrollo.*
 *   3) Sesión de superadmin activa
 *
 * Sin esos chequeos se devuelve 403.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/core/config/platform.php';

// -----------------------------
// Gate de seguridad
// -----------------------------
$envDev = getenv('ASSET_VAULT_DEV') === '1';
$host   = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$hostDev =
    str_starts_with($host, 'localhost')
    || str_starts_with($host, '127.0.0.1')
    || str_ends_with($host, '.local')
    || str_starts_with($host, 'desarrollo.')
    || str_starts_with($host, 'dev.')
    || str_starts_with($host, 'staging.');

$isSuperadmin = !empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true;

if (!$envDev && !$hostDev && !$isSuperadmin) {
    http_response_code(403);
    echo '<h1>403 — Acceso denegado</h1><p>Esta utilidad solo está disponible en entornos de desarrollo o para superadmins.</p>';
    exit;
}

// -----------------------------
// Si llega por GET sin confirm → mostrar página de confirmación
// (Evita disparos accidentales con un simple link.)
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['confirm'] ?? '') !== 'RESETEAR_TODO') {
    ?>
    <!doctype html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Reset Tenant · Asset Vault (DEV)</title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
      <link href="/assets/css/intellihelp.css" rel="stylesheet">
    </head>
    <body>
    <div class="ih-auth">
      <div class="ih-auth__card ih-anim-fade">
        <div class="ih-auth__header" style="background:linear-gradient(135deg, #DC2626, #991B1B);color:#fff;border-radius:20px 20px 0 0;padding:24px 28px;text-align:center;">
          <div style="font-size:48px"><i class="bi bi-exclamation-triangle-fill"></i></div>
          <h2 style="color:#fff;margin:8px 0 4px;font-size:22px;font-weight:700">Reset destructivo del tenant</h2>
          <p style="color:rgba(255,255,255,.85);font-size:13px;margin:0">Solo usar en entornos de desarrollo</p>
        </div>
        <div class="ih-auth__body">
          <div class="ih-alert ih-alert--danger">
            <i class="bi bi-trash-fill"></i>
            <div>
              <strong>Vas a borrar TODAS las tablas del tenant actual:</strong>
              <code><?= htmlspecialchars((string)($_SESSION['company_id'] ?? 'desconocido')) ?></code>.<br>
              Después tendrás que volver a correr <code>install.php</code> para recrear el esquema.
            </div>
          </div>

          <p class="ih-text-sm ih-text-muted">
            Esta operación NO se puede deshacer. Los archivos en Google Drive permanecen, pero la metadata local se pierde.
            Usá esta herramienta solo cuando estás probando el flujo de instalación desde cero.
          </p>

          <form method="post" onsubmit="return confirm('¿De verdad querés borrar todas las tablas del tenant?')">
            <input type="hidden" name="confirm" value="RESETEAR_TODO">
            <button class="ih-btn ih-btn--danger ih-btn--block ih-btn--lg" type="submit">
              <i class="bi bi-trash"></i> Sí, resetear todas las tablas
            </button>
          </form>
          <a href="/index.php" class="ih-btn ih-btn--ghost ih-btn--block ih-mt-2">Cancelar</a>
        </div>
        <div class="ih-auth__footer">
          DEV TOOL · INTELLIHELP S.A.
        </div>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// -----------------------------
// Ejecutar reset
// -----------------------------
require_once __DIR__ . '/backend/db.php';

$tenant_id = $_SESSION['company_id'] ?? 'sin_sesion';
$borradas  = [];
$errores   = [];

try {
    // Desactivar checks de FK por las dudas
    @$conn->query("SET FOREIGN_KEY_CHECKS = 0");
    // Borrar triggers
    @$conn->query("DROP TRIGGER IF EXISTS trg_productos_after_update");

    $res = $conn->query("SHOW TABLES");
    if ($res) {
        while ($row = $res->fetch_row()) {
            $t = $row[0];
            if ($conn->query("DROP TABLE IF EXISTS `$t`") === true) {
                $borradas[] = $t;
            } else {
                $errores[] = "$t: " . $conn->error;
            }
        }
    }
    @$conn->query("SET FOREIGN_KEY_CHECKS = 1");
} catch (Throwable $e) {
    $errores[] = $e->getMessage();
}

// Limpiar sesión local del tenant (no la de superadmin)
unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['access_token'],
      $_SESSION['google_authenticated'], $_SESSION['user_email'],
      $_SESSION['user_name'], $_SESSION['user_picture'],
      $_SESSION['company_id'], $_SESSION['archivo_actual'], $_SESSION['form_data']);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset completado · Asset Vault</title>
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
          <span>Asset Vault · DEV</span>
        </div>
      </div>
      <h2 class="ih-auth__title">Reset completado</h2>
      <p class="ih-auth__subtitle">Tenant <code><?= htmlspecialchars((string)$tenant_id) ?></code></p>
    </div>
    <div class="ih-auth__body">
      <div class="ih-alert ih-alert--success">
        <i class="bi bi-check-circle-fill"></i>
        <div><strong><?= count($borradas) ?></strong> tablas eliminadas correctamente.</div>
      </div>
      <?php if (!empty($errores)): ?>
        <div class="ih-alert ih-alert--danger">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div>
            Errores:
            <ul style="margin:6px 0 0 0;padding-left:20px"><?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
          </div>
        </div>
      <?php endif; ?>
      <?php if (!empty($borradas)): ?>
        <details>
          <summary class="ih-text-sm ih-text-muted ih-fw-600">Ver detalle</summary>
          <ul class="ih-text-xs ih-text-muted" style="margin-top:8px;padding-left:18px">
            <?php foreach ($borradas as $t): ?><li><code><?= htmlspecialchars($t) ?></code></li><?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
      <a href="/install.php" class="ih-btn ih-btn--primary ih-btn--block ih-mt-4">
        <i class="bi bi-arrow-clockwise"></i> Reinstalar ahora
      </a>
      <a href="/auth/superadmin_login.php" class="ih-btn ih-btn--ghost ih-btn--block ih-mt-2">
        Ir al panel SuperAdmin
      </a>
    </div>
    <div class="ih-auth__footer">
      DEV TOOL · INTELLIHELP S.A.
    </div>
  </div>
</div>
</body>
</html>
