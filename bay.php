<?php
/**
 * bay.php — Reset COMPLETO de la plataforma (DESARROLLO)
 *
 * Borra TODO:
 *   1. Tablas de cada tenant registrado en el master DB (DB externas y prefijadas)
 *   2. Todos los datos del master DB: companies, users_platform, platform_config
 *   3. La sesión activa completa
 *
 * Al terminar redirige a install.php para empezar desde cero.
 *
 * Gate de seguridad — solo disponible bajo:
 *   a) Variable de entorno ASSET_VAULT_DEV=1
 *   b) Host que coincida con localhost / *.local / desarrollo.* / dev.* / staging.*
 *   c) Sesión de superadmin activa
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/core/config/platform.php';

// ─── Gate de seguridad ───────────────────────────────────────────────────────
$envDev = getenv('ASSET_VAULT_DEV') === '1';
$host   = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$hostDev =
    str_starts_with($host, 'localhost')    ||
    str_starts_with($host, '127.0.0.1')   ||
    str_ends_with($host, '.local')         ||
    str_starts_with($host, 'desarrollo.')  ||
    str_starts_with($host, 'dev.')         ||
    str_starts_with($host, 'staging.');

$isSuperadmin = !empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true;

if (!$envDev && !$hostDev && !$isSuperadmin) {
    http_response_code(403);
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><title>403</title>
    <link href="/assets/css/intellihelp.css" rel="stylesheet"></head>
    <body><div class="ih-auth"><div class="ih-auth__card"><div class="ih-auth__body">
    <div class="ih-alert ih-alert--danger"><i class="bi bi-shield-x"></i><div>Acceso denegado. Esta herramienta solo está disponible en entornos de desarrollo o para superadmins.</div></div>
    </div></div></div></body></html>
    <?php
    exit;
}

// ─── GET: pantalla de confirmación ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['confirm'] ?? '') !== 'RESETEAR_TODO') {

    // Intentar mostrar qué se va a borrar
    require_once __DIR__ . '/core/db/master_connection.php';
    $previewCompanies = [];
    try {
        $previewMaster = new MasterDB();
        $chk = $previewMaster->conn->query("SHOW TABLES LIKE 'companies'");
        if ($chk && $chk->num_rows > 0) {
            $res = $previewMaster->conn->query("SELECT nombre, tenant_mode, db_name FROM companies ORDER BY id ASC");
            if ($res) {
                while ($r = $res->fetch_assoc()) { $previewCompanies[] = $r; }
            }
        }
    } catch (Throwable) {}
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset total · Asset Vault (DEV)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/assets/css/intellihelp.css" rel="stylesheet">
</head>
<body>
<div class="ih-auth">
  <div class="ih-auth__card ih-anim-fade" style="max-width:520px">
    <div class="ih-auth__header" style="background:linear-gradient(135deg,#DC2626,#991B1B);color:#fff;border-radius:20px 20px 0 0;padding:24px 28px;text-align:center">
      <div style="font-size:52px"><i class="bi bi-exclamation-triangle-fill"></i></div>
      <h2 style="color:#fff;margin:8px 0 4px;font-size:20px;font-weight:700">Reset total de la plataforma</h2>
      <p style="color:rgba(255,255,255,.8);font-size:13px;margin:0">Herramienta destructiva — solo desarrollo</p>
    </div>

    <div class="ih-auth__body">
      <div class="ih-alert ih-alert--danger">
        <i class="bi bi-trash3-fill"></i>
        <div>
          <strong>Esto va a borrar sin posibilidad de recuperación:</strong>
          <ul style="margin:8px 0 0;padding-left:18px;font-size:13px;line-height:1.8">
            <li>Todas las tablas de cada tenant registrado</li>
            <li>Todos los registros del master DB (empresas, superadmins, config)</li>
            <li>La sesión activa</li>
          </ul>
        </div>
      </div>

      <?php if (!empty($previewCompanies)): ?>
        <div class="ih-text-sm ih-text-muted" style="margin-bottom:12px">
          <strong>Tenants que se van a limpiar:</strong>
          <ul style="margin:6px 0 0;padding-left:18px">
            <?php foreach ($previewCompanies as $c): ?>
              <li><?= htmlspecialchars($c['nombre']) ?> — <code><?= htmlspecialchars($c['db_name'] ?? 'prefijo en master') ?></code> (<?= htmlspecialchars($c['tenant_mode']) ?>)</li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php else: ?>
        <p class="ih-text-sm ih-text-muted">No hay empresas registradas (o master DB vacío).</p>
      <?php endif; ?>

      <form method="post" onsubmit="return confirm('¿Confirmar reset total? Esto no se puede deshacer.')">
        <input type="hidden" name="confirm" value="RESETEAR_TODO">
        <button class="ih-btn ih-btn--danger ih-btn--block ih-btn--lg" type="submit" style="background:#DC2626;border-color:#DC2626">
          <i class="bi bi-trash3"></i> Sí, borrar todo y empezar de cero
        </button>
      </form>
      <a href="<?= $isSuperadmin ? '/dashboard/dashboard_superadmin.php' : '/login.php' ?>" class="ih-btn ih-btn--ghost ih-btn--block ih-mt-2">
        Cancelar
      </a>
    </div>

    <div class="ih-auth__footer">DEV TOOL · INTELLIHELP S.A.</div>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ─── POST: ejecutar reset ─────────────────────────────────────────────────────
require_once __DIR__ . '/core/db/master_connection.php';

$log    = [];
$errors = [];

try {
    $master = new MasterDB();

    // 1. Enumerar empresas y borrar sus tablas de tenant
    $chkComp = $master->conn->query("SHOW TABLES LIKE 'companies'");
    if ($chkComp && $chkComp->num_rows > 0) {
        $compRes = $master->conn->query("SELECT id, nombre, tenant_mode, db_host, db_name, db_user, db_pass FROM companies ORDER BY id ASC");
        if ($compRes) {
            while ($c = $compRes->fetch_assoc()) {

                if ($c['tenant_mode'] === 'external') {
                    // DB separada: conectar y borrar todas sus tablas
                    try {
                        $tc = @new mysqli(
                            $c['db_host'] ?: 'localhost',
                            $c['db_user'],
                            $c['db_pass'],
                            $c['db_name']
                        );
                        if ($tc->connect_errno) {
                            throw new Exception($tc->connect_error);
                        }
                        $tc->query("SET FOREIGN_KEY_CHECKS = 0");
                        @$tc->query("DROP TRIGGER IF EXISTS trg_productos_after_update");
                        $tr = $tc->query("SHOW TABLES");
                        $n  = 0;
                        if ($tr) {
                            while ($trow = $tr->fetch_row()) {
                                $tc->query("DROP TABLE IF EXISTS `" . $tc->real_escape_string($trow[0]) . "`");
                                $n++;
                            }
                        }
                        $tc->query("SET FOREIGN_KEY_CHECKS = 1");
                        $tc->close();
                        $log[] = ['ok', "Tenant «{$c['nombre']}» ({$c['db_name']}): $n tablas eliminadas"];
                    } catch (Throwable $te) {
                        $errors[] = "Tenant «{$c['nombre']}»: " . $te->getMessage();
                    }

                } else {
                    // Modo prefijo: borrar tablas t{id}_* del master DB
                    $prefix = TENANT_PREFIX_BASE . ((int)$c['id']) . '_';
                    $safe   = $master->conn->real_escape_string($prefix);
                    $tr = $master->conn->query("SHOW TABLES LIKE '{$safe}%'");
                    $n  = 0;
                    if ($tr && $tr->num_rows > 0) {
                        $master->conn->query("SET FOREIGN_KEY_CHECKS = 0");
                        while ($trow = $tr->fetch_row()) {
                            $master->conn->query("DROP TABLE IF EXISTS `" . $master->conn->real_escape_string($trow[0]) . "`");
                            $n++;
                        }
                        $master->conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    }
                    $log[] = ['ok', "Tenant «{$c['nombre']}» (prefijo {$prefix}): $n tablas eliminadas"];
                }
            }
        }
    } else {
        $log[] = ['info', 'Tabla companies no existe — nada de tenants que limpiar'];
    }

    // 2. Vaciar tablas del master DB
    foreach (['platform_config', 'companies', 'users_platform'] as $t) {
        $exists = $master->conn->query("SHOW TABLES LIKE '{$t}'");
        if ($exists && $exists->num_rows > 0) {
            $master->conn->query("DELETE FROM `{$t}`");
            $master->conn->query("ALTER TABLE `{$t}` AUTO_INCREMENT = 1");
            $log[] = ['ok', "Master DB: tabla `{$t}` vaciada"];
        }
    }

} catch (Throwable $e) {
    $errors[] = 'Error crítico: ' . $e->getMessage();
}

// 3. Destruir sesión completamente
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
session_destroy();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset completado · Asset Vault</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/assets/css/intellihelp.css" rel="stylesheet">
  <?php if (empty($errors)): ?>
  <meta http-equiv="refresh" content="4;url=/install.php">
  <?php endif; ?>
</head>
<body>
<div class="ih-auth">
  <div class="ih-auth__card ih-anim-fade" style="max-width:520px">
    <div class="ih-auth__header">
      <div class="ih-auth__brand">
        <div class="ih-auth__logo">i</div>
        <div class="ih-auth__brand-text">
          <strong>INTELLIHELP</strong>
          <span>Asset Vault · DEV</span>
        </div>
      </div>
      <h2 class="ih-auth__title">Reset completado</h2>
      <p class="ih-auth__subtitle">La plataforma quedó limpia desde cero</p>
    </div>

    <div class="ih-auth__body">

      <?php if (!empty($errors)): ?>
        <div class="ih-alert ih-alert--danger">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div>
            <strong>Errores durante el reset:</strong>
            <ul style="margin:6px 0 0;padding-left:18px;font-size:13px">
              <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($log)): ?>
        <div class="ih-alert ih-alert--success">
          <i class="bi bi-check-circle-fill"></i>
          <div><strong>Operaciones realizadas:</strong></div>
        </div>
        <ul style="margin:0 0 16px;padding-left:18px;font-size:13px;line-height:1.9">
          <?php foreach ($log as [$type, $msg]): ?>
            <li><?= htmlspecialchars($msg) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (empty($errors)): ?>
        <div class="ih-alert ih-alert--info">
          <i class="bi bi-arrow-clockwise"></i>
          <div>Redirigiendo a <strong>install.php</strong> en 4 segundos…</div>
        </div>
      <?php endif; ?>

      <a href="/install.php" class="ih-btn ih-btn--primary ih-btn--block" style="margin-top:16px">
        <i class="bi bi-arrow-clockwise"></i> Ir a instalar ahora
      </a>

    </div>
    <div class="ih-auth__footer">DEV TOOL · INTELLIHELP S.A.</div>
  </div>
</div>
</body>
</html>
