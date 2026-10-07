<?php
/**
 * Datos: sacar el inventario del sistema y volver a meterlo.
 *
 * Antes eran dos pestañas, «Exportar» e «Importar», y la segunda abría una
 * pantalla titulada «Backup & Migración» que además exportaba. El rótulo no
 * coincidía con lo que había adentro, y las dos cosas que hacía —una copia de
 * seguridad completa y una carga masiva— quedaban mezcladas bajo un verbo.
 *
 * Acá se separan por lo que resuelven, no por el archivo que las implementa:
 * trabajar el inventario afuera (CSV), y respaldarlo o traerlo de otro lado.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start();

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../includes/hub.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

$user = require_tenant_permission_any([
    'asset.export', 'asset.import_csv', 'asset.backup_full',
]);
$rol = (string)($user['role'] ?? 'viewer');

$puedeExportar = role_can('asset.export', $rol);
$puedeImportar = role_can('asset.import_csv', $rol);
$puedeBackup   = role_can('asset.backup_full', $rol);

// Cuántos activos hay: le da escala a la pantalla antes de bajar nada.
$cuantos = 0;
try {
    $rs = $conn->query('SELECT COUNT(*) c FROM `' . tn('productos') . '` WHERE archivado = 0');
    if ($rs && ($r = $rs->fetch_assoc())) { $cuantos = (int)$r['c']; }
} catch (Throwable) {}

require_once __DIR__ . '/../core/db/empresa_cache.php';
$tenant_name = empresa_nombre();

$page_title      = 'Datos — Asset Vault';
$page_section    = 'datos';
$page_portal     = 'tenant';
$topbar_title    = 'Datos';
$topbar_subtitle = 'Sacar el inventario del sistema y volver a traerlo';
$topbar_user     = $user['username'] ?? '';
$topbar_email    = $user['email']    ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';

require __DIR__ . '/../includes/layout/head.php';
?>
<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>
<main class="ih-app__main">

  <?php hub_grupo('Planillas CSV'); ?>
  <?php hub_tarjetas([
      [
          'url'      => '/inventario/exportar.php',
          'icono'    => 'bi-filetype-csv',
          'titulo'   => 'Exportar a CSV',
          'desc'     => 'Bajá el inventario para abrirlo en Excel o en Google Sheets. '
                      . 'Podés volver a subir el mismo archivo después de editarlo.',
          'puede'    => $puedeExportar,
          'insignia' => $cuantos > 0 ? $cuantos . ($cuantos === 1 ? ' activo' : ' activos') : null,
      ],
      [
          'url'    => '/inventario/importar.php',
          'icono'  => 'bi-upload',
          'titulo' => 'Importar desde CSV',
          'desc'   => 'Dar de alta muchos activos de una vez, desde una planilla. '
                    . 'Sirve también para traer el inventario de otro sistema.',
          'puede'  => $puedeImportar || $puedeBackup,
      ],
  ]); ?>

  <?php hub_grupo('Respaldo'); ?>
  <?php hub_tarjetas([
      [
          'url'    => '/inventario/importar.php#backup',
          'icono'  => 'bi-shield-check',
          'titulo' => 'Copia de seguridad completa',
          'desc'   => 'Una copia de todo el inventario de ' . $tenant_name . ', con el historial '
                    . 'de movimientos y los campos propios. Guardala fuera del sistema.',
          'puede'  => $puedeBackup,
      ],
  ]); ?>

  <div class="ih-alert ih-alert--info ih-mt-5 ih-ancho-lectura">
    <i class="bi bi-info-circle-fill"></i>
    <div>
      El archivo que baja «Exportar a CSV» se puede volver a importar tal cual. Las dos
      columnas que no vuelven son a propósito: la fecha de carga la pone el sistema, y el
      estado de archivado no se toca desde una planilla para que una importación no mande
      activos a la papelera sin avisar.
    </div>
  </div>

</main>
</div><!-- /ih-app -->
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
