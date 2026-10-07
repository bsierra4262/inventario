<?php
/**
 * Navegación del portal tenant — INTELLIHELP
 *
 * El markup conserva las clases `ih-sidebar*` porque así lo llaman las pantallas
 * que arman la página, pero assets/css/app.css lo dibuja como una fila de
 * pestañas debajo del topbar: la misma silueta que el CRM y APP Tickets.
 *
 * ## Por qué cinco y no once
 *
 * Esta fila llegó a tener once entradas: Activos, Nuevo, Papelera, Reportes,
 * Exportar, Importar, Listas, Campos, Drive, Usuarios y Roles. Tres problemas:
 *
 *   1. «Nuevo» repetía el botón «+ Nuevo activo» que ya está en el topbar.
 *   2. «Listas» y «Campos» no significan nada fuera de contexto.
 *   3. «Importar» abría una pantalla titulada «Backup & Migración»: el rótulo
 *      no coincidía con lo que había adentro.
 *
 * Quedan las cuatro cosas que se hacen seguido —ver activos, mirar reportes,
 * recuperar algo de la papelera— más dos índices, Datos y Configuración, donde
 * cada opción se presenta con una frase que explica para qué sirve. Lo que se
 * usa todos los días no quedó a más clics: «Nuevo activo» y «Exportar» siguen
 * siendo botones del topbar en la pantalla de activos.
 *
 * Variables esperadas:
 *   $page_section string
 *   $tenant_name  string (nombre de la empresa actual)
 */
$page_section = $page_section ?? '';
$tenant_name  = $tenant_name  ?? 'Mi empresa';

/* ¿Tiene sentido ofrecer «Contraseña»?
   Si la empresa deshabilitó el acceso con usuario y contraseña —todos entran
   con Google o con Microsoft— nadie tiene una contraseña que cambiar, y el
   enlace lleva a una pantalla que no sirve para nada. Pedía cambiar algo que
   no se usa para entrar. */
$sb_hay_password = true;
if (isset($conn) && $conn instanceof mysqli) {
    $sb_ts = __DIR__ . '/../../core/tenants/tenant_settings.php';
    if (is_file($sb_ts)) {
        require_once $sb_ts;
        if (function_exists('tenant_local_login_disabled')) {
            try { $sb_hay_password = !tenant_local_login_disabled($conn); }
            catch (Throwable) { $sb_hay_password = true; }
        }
    }
}

if (!function_exists('ih_tn_active')) {
/**
 * Marca la pestaña activa. Una pestaña índice también se queda marcada mientras
 * se está en alguna de las pantallas que agrupa: si no, al entrar a «Usuarios»
 * no quedaba ninguna resaltada y la fila parecía de otra sección.
 */
function ih_tn_active(string $key, string $current, array $hijas = []): string {
    return ($key === $current || in_array($current, $hijas, true)) ? ' is-active' : '';
}
}

require_once __DIR__ . '/../../core/security/permissions.php';

$sb_role = (string)($_SESSION['role'] ?? 'viewer');

$sb_can_report  = role_can('report.view', $sb_role);
$sb_can_trash   = role_can('asset.trash.view', $sb_role);
$sb_can_catalog = role_can('catalog.manage', $sb_role);
$sb_can_intedit = role_can('integration.edit', $sb_role);
$sb_can_userman = role_can('user.view', $sb_role) || role_can('user.edit', $sb_role);
$sb_can_export  = role_can('asset.export', $sb_role);
$sb_can_import  = role_can('asset.import_csv', $sb_role) || role_can('asset.backup_full', $sb_role);

// Los índices aparecen si hay algo adentro que este rol pueda abrir.
$sb_can_datos  = $sb_can_export || $sb_can_import;
$sb_can_config = $sb_can_catalog || $sb_can_intedit || $sb_can_userman;

// Las pantallas que cuelgan de cada índice, para que la pestaña siga marcada.
$sb_hijas_datos  = ['exportar', 'importar'];
$sb_hijas_config = ['catalogos', 'campos', 'integracion', 'usuarios', 'roles'];
?>
<nav class="ih-sidebar ih-app__sidebar" aria-label="Secciones">
  <div class="ih-sidebar__nav">
    <div class="ih-sidebar__group">

      <a href="/inventario/index.php"
         class="ih-sidebar__link<?= ih_tn_active('inventario', $page_section, ['agregar']) ?>">
        <i class="bi bi-box-seam"></i> Activos
      </a>

      <?php if ($sb_can_report): ?>
        <a href="/inventario/reportes.php"
           class="ih-sidebar__link<?= ih_tn_active('reportes', $page_section) ?>">
          <i class="bi bi-graph-up"></i> Reportes
        </a>
      <?php endif; ?>

      <?php if ($sb_can_trash): ?>
        <a href="/inventario/archivados.php"
           class="ih-sidebar__link<?= ih_tn_active('archivados', $page_section) ?>">
          <i class="bi bi-archive"></i> Papelera
        </a>
      <?php endif; ?>

      <?php if ($sb_can_datos): ?>
        <a href="/inventario/datos.php"
           class="ih-sidebar__link<?= ih_tn_active('datos', $page_section, $sb_hijas_datos) ?>">
          <i class="bi bi-arrow-down-up"></i> Datos
        </a>
      <?php endif; ?>

      <?php if ($sb_can_config): ?>
        <a href="/inventario/configuracion.php"
           class="ih-sidebar__link<?= ih_tn_active('configuracion', $page_section, $sb_hijas_config) ?>">
          <i class="bi bi-gear"></i> Configuración
        </a>
      <?php endif; ?>

    </div>
  </div>

  <div class="ih-sidebar__group ih-sidebar__group--fin">
    <?php if ($sb_hay_password): ?>
      <a href="/auth/change_password.php" class="ih-sidebar__link" title="Cambiar contraseña">
        <i class="bi bi-key"></i><span class="ih-hidden-mobile"> Contraseña</span>
      </a>
    <?php endif; ?>
    <a href="/auth/logout.php" class="ih-sidebar__link" title="Cerrar sesión">
      <i class="bi bi-box-arrow-right"></i><span class="ih-hidden-mobile"> Salir</span>
    </a>
  </div>
</nav>
