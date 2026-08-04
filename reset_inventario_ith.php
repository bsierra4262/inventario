<?php
/**
 * reset_inventario_ith.php — Vacía SOLO el inventario del tenant ITH.
 *
 * Borra únicamente:
 *   - productos            (los activos)
 *   - producto_movimientos (historial de movimientos)
 *
 * NO toca: usuarios, roles, cloud_config, la empresa en el master DB ni el login.
 * Pensado para dejar la demo con inventario en cero sin perder el acceso.
 *
 * Gate de seguridad (igual que bay.php) — solo disponible bajo:
 *   a) Variable de entorno ASSET_VAULT_DEV=1
 *   b) Host desarrollo.* / dev.* / staging.* / localhost / *.local
 *   c) Sesión de superadmin activa
 *
 * BORRAR ESTE ARCHIVO del servidor después de usarlo.
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/core/config/platform.php';
require_once __DIR__ . '/core/db/master_connection.php';
require_once __DIR__ . '/core/tenants/install_tenant.php';

// ─── Gate de seguridad ───────────────────────────────────────────────────────
$envDev = getenv('ASSET_VAULT_DEV') === '1';
$host   = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$hostDev =
    str_starts_with($host, 'localhost')   ||
    str_starts_with($host, '127.0.0.1')   ||
    str_ends_with($host,   '.local')      ||
    str_starts_with($host, 'desarrollo.') ||
    str_starts_with($host, 'dev.')        ||
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

// ─── Localizar el tenant ITH en el master DB ────────────────────────────────
$master = new MasterDB();

// Coincidencia por subdomain='ITH' (preferido) o nombre que contenga ITH.
$stmt = $master->pquery(
    "SELECT id, nombre, subdomain, tenant_mode, db_name
       FROM companies
      WHERE UPPER(subdomain) = 'ITH' OR UPPER(nombre) LIKE '%ITH%'
      ORDER BY (UPPER(subdomain) = 'ITH') DESC, id ASC
      LIMIT 1",
    '', []
);
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fatal = null;
$countProductos    = null;
$countMovimientos  = null;
$conn = null;
$prefix = '';

if (!$company) {
    $fatal = "No se encontró ninguna empresa ITH en el master DB (busqué por subdomain='ITH' o nombre con 'ITH').";
} else {
    try {
        $t      = tenant_conn_by_company((int)$company['id']);
        $conn   = $t['conn'];
        $prefix = $t['prefix'];

        $tblProd = $conn->real_escape_string($prefix . 'productos');
        $tblMov  = $conn->real_escape_string($prefix . 'producto_movimientos');

        $r1 = $conn->query("SELECT COUNT(*) AS n FROM `{$tblProd}`");
        $countProductos = $r1 ? (int)$r1->fetch_assoc()['n'] : 0;

        $r2 = $conn->query("SELECT COUNT(*) AS n FROM `{$tblMov}`");
        $countMovimientos = $r2 ? (int)$r2->fetch_assoc()['n'] : 0;
    } catch (Throwable $e) {
        $fatal = 'Error conectando al tenant ITH: ' . $e->getMessage();
    }
}

$doReset = ($_SERVER['REQUEST_METHOD'] === 'POST'
            && ($_POST['confirm'] ?? '') === 'VACIAR_INVENTARIO_ITH'
            && $company && !$fatal);

$log    = [];
$errors = [];

if ($doReset) {
    try {
        $tblProd = $conn->real_escape_string($prefix . 'productos');
        $tblMov  = $conn->real_escape_string($prefix . 'producto_movimientos');

        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        // Borrar historial primero, luego los activos.
        if ($conn->query("DELETE FROM `{$tblMov}`")) {
            $conn->query("ALTER TABLE `{$tblMov}` AUTO_INCREMENT = 1");
            $log[] = "Historial de movimientos vaciado (`{$tblMov}`)";
        } else {
            $errors[] = "No se pudo vaciar `{$tblMov}`: " . $conn->error;
        }

        if ($conn->query("DELETE FROM `{$tblProd}`")) {
            $conn->query("ALTER TABLE `{$tblProd}` AUTO_INCREMENT = 1");
            $log[] = "Activos vaciados (`{$tblProd}`)";
        } else {
            $errors[] = "No se pudo vaciar `{$tblProd}`: " . $conn->error;
        }

        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    } catch (Throwable $e) {
        $errors[] = 'Error crítico durante el vaciado: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vaciar inventario ITH · Asset Vault</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/assets/css/intellihelp.css" rel="stylesheet">
</head>
<body>
<div class="ih-auth">
  <div class="ih-auth__card ih-anim-fade" style="max-width:520px">

<?php if ($doReset): ?>
    <div class="ih-auth__header">
      <div class="ih-auth__brand">
        <div class="ih-auth__logo"><?= platform_logo_html() ?></div>
        <div class="ih-auth__brand-text"><strong>INTELLIHELP</strong><span>Asset Vault</span></div>
      </div>
      <h2 class="ih-auth__title">Inventario ITH vaciado</h2>
      <p class="ih-auth__subtitle">El tenant quedó con el inventario en cero</p>
    </div>
    <div class="ih-auth__body">
      <?php if (!empty($errors)): ?>
        <div class="ih-alert ih-alert--danger">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div><strong>Errores:</strong>
            <ul style="margin:6px 0 0;padding-left:18px;font-size:13px">
              <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>
      <?php if (!empty($log)): ?>
        <div class="ih-alert ih-alert--success"><i class="bi bi-check-circle-fill"></i><div><strong>Listo:</strong></div></div>
        <ul style="margin:0 0 16px;padding-left:18px;font-size:13px;line-height:1.9">
          <?php foreach ($log as $msg): ?><li><?= htmlspecialchars($msg) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="ih-alert ih-alert--info">
        <i class="bi bi-shield-lock"></i>
        <div>Usuarios, roles, login y config de ITH quedaron intactos. <strong>Acordate de borrar este archivo del servidor.</strong></div>
      </div>
      <a href="/dashboard/dashboard_superadmin.php" class="ih-btn ih-btn--primary ih-btn--block" style="margin-top:16px">Ir al panel</a>
    </div>

<?php elseif ($fatal): ?>
    <div class="ih-auth__body">
      <div class="ih-alert ih-alert--danger"><i class="bi bi-x-octagon-fill"></i><div><?= htmlspecialchars($fatal) ?></div></div>
      <a href="/dashboard/dashboard_superadmin.php" class="ih-btn ih-btn--ghost ih-btn--block">Volver</a>
    </div>

<?php else: ?>
    <div class="ih-auth__header" style="background:linear-gradient(135deg,#DC2626,#991B1B);color:#fff;border-radius:20px 20px 0 0;padding:24px 28px;text-align:center">
      <div style="font-size:52px"><i class="bi bi-trash3-fill"></i></div>
      <h2 style="color:#fff;margin:8px 0 4px;font-size:20px;font-weight:700">Vaciar inventario de ITH</h2>
      <p style="color:rgba(255,255,255,.8);font-size:13px;margin:0">Solo activos e historial — el acceso queda intacto</p>
    </div>
    <div class="ih-auth__body">
      <div class="ih-text-sm ih-text-muted" style="margin-bottom:12px">
        <strong>Empresa:</strong> <?= htmlspecialchars($company['nombre']) ?>
        (subdomain <code><?= htmlspecialchars($company['subdomain'] ?: '—') ?></code>, modo <?= htmlspecialchars($company['tenant_mode']) ?>)
      </div>
      <div class="ih-alert ih-alert--danger">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
          <strong>Se va a borrar sin vuelta atrás:</strong>
          <ul style="margin:8px 0 0;padding-left:18px;font-size:13px;line-height:1.8">
            <li><strong><?= (int)$countProductos ?></strong> activos (productos)</li>
            <li><strong><?= (int)$countMovimientos ?></strong> registros de historial</li>
          </ul>
        </div>
      </div>
      <div class="ih-alert ih-alert--info">
        <i class="bi bi-shield-check"></i>
        <div>Usuarios, roles, login y config <strong>NO</strong> se tocan.</div>
      </div>
      <form method="post" onsubmit="return confirm('¿Vaciar el inventario de ITH? Esto no se puede deshacer.')">
        <input type="hidden" name="confirm" value="VACIAR_INVENTARIO_ITH">
        <button class="ih-btn ih-btn--danger ih-btn--block ih-btn--lg" type="submit" style="background:#DC2626;border-color:#DC2626">
          <i class="bi bi-trash3"></i> Sí, vaciar inventario de ITH
        </button>
      </form>
      <a href="/dashboard/dashboard_superadmin.php" class="ih-btn ih-btn--ghost ih-btn--block ih-mt-2">Cancelar</a>
    </div>
<?php endif; ?>

    <div class="ih-auth__footer">DEV TOOL · INTELLIHELP S.A.</div>
  </div>
</div>
</body>
</html>
