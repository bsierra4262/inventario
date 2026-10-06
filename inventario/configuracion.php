<?php
/**
 * Configuración: el índice de todo lo que se ajusta una vez y casi no se toca.
 *
 * Antes eran cinco pestañas sueltas en la fila principal —Listas, Campos,
 * Drive, Usuarios, Roles— mezcladas con las que se usan todos los días. Dos de
 * esos nombres no significaban nada fuera de contexto: «Listas» y «Campos».
 *
 * Acá cada opción lleva una frase que dice para qué sirve, y las que el rol
 * actual no puede usar se ven apagadas en vez de desaparecer: así la pantalla
 * no cambia de forma según quién entra, y quien no tiene el permiso ve que la
 * función existe y puede pedirla.
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
require_once __DIR__ . '/../includes/cloud_icons.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

$user = require_tenant_permission_any([
    'catalog.manage', 'integration.edit', 'user.view', 'user.edit', 'asset.view',
]);
$rol = (string)($user['role'] ?? 'viewer');

$puedeCatalogos = role_can('catalog.manage', $rol);
$puedeIntegra   = role_can('integration.edit', $rol);
$puedeUsuarios  = role_can('user.view', $rol) || role_can('user.edit', $rol);
$puedeContratos = role_can('asset.view', $rol);

// El proveedor configurado decide el nombre: decir «Drive» cuando la empresa
// usa OneDrive confunde más de lo que ayuda.
$proveedor = ($conn instanceof mysqli) ? cloud_provider_actual($conn) : 'google';
$esOneDrive = strtolower((string)$proveedor) === 'microsoft';
$nombreNube = $esOneDrive ? 'Microsoft OneDrive' : 'Google Drive';

$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $masterDb  = new MasterDB();
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if ($companyId > 0) {
        $cst  = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
        $crow = $cst->get_result()->fetch_assoc();
        if ($crow) { $tenant_name = (string)$crow['nombre']; }
        $cst->close();
    }
} catch (Throwable) {}

$page_title      = 'Configuración — Asset Vault';
$page_section    = 'configuracion';
$page_portal     = 'tenant';
$topbar_title    = 'Configuración';
$topbar_subtitle = 'Lo que se ajusta una vez y queda andando';
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

  <?php hub_grupo('El inventario'); ?>
  <?php hub_tarjetas([
      [
          'url'    => '/inventario/catalogos.php',
          'icono'  => 'bi-list-ul',
          'titulo' => 'Listas desplegables',
          'desc'   => 'Las opciones que aparecen en Categoría, Estado, Ubicación y Asignado a. '
                    . 'Agregá las tuyas y desactivá las que no uses.',
          'puede'  => $puedeCatalogos,
      ],
      [
          'url'    => '/inventario/campos.php',
          'icono'  => 'bi-sliders',
          'titulo' => 'Campos por categoría',
          'desc'   => 'Qué datos se le piden a cada tipo de activo. Una notebook y una bobina '
                    . 'de cable no necesitan lo mismo.',
          'puede'  => $puedeCatalogos,
      ],
  ]); ?>

  <?php hub_grupo('Compras y cobertura'); ?>
  <?php hub_tarjetas([
      [
          'url'    => '/inventario/contratos.php',
          'icono'  => 'bi-file-earmark-text',
          'titulo' => 'Proveedores y contratos',
          'desc'   => 'A quién le compramos y hasta cuándo tenemos soporte. Avisa lo que vence '
                    . 'en los próximos noventa días.',
          'puede'  => $puedeContratos,
      ],
  ]); ?>

  <?php hub_grupo('Quién entra'); ?>
  <?php hub_tarjetas([
      [
          'url'    => '/inventario/usuarios.php',
          'icono'  => 'bi-people',
          'titulo' => 'Usuarios',
          'desc'   => 'Las personas de ' . $tenant_name . ' que pueden abrir el inventario.',
          'puede'  => $puedeUsuarios,
      ],
      [
          'url'    => '/inventario/roles.php',
          'icono'  => 'bi-shield-lock',
          'titulo' => 'Roles y permisos',
          'desc'   => 'Qué puede hacer cada uno: mirar, cargar, editar, borrar o administrar.',
          'puede'  => $puedeUsuarios,
      ],
  ]); ?>

  <?php hub_grupo('Archivos adjuntos'); ?>
  <?php hub_tarjetas([
      [
          'url'      => '/inventario/configurar_integracion.php',
          'icono'    => $esOneDrive ? 'bi-microsoft' : 'bi-google',
          'titulo'   => 'Adjuntos en la nube',
          'desc'     => 'Las facturas y fotos de cada activo se suben a la nube de la empresa. '
                      . 'Acá se elige la cuenta y la carpeta.',
          'puede'    => $puedeIntegra,
          'insignia' => $nombreNube,
      ],
  ]); ?>

</main>
</div><!-- /ih-app -->
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
