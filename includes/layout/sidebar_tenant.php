<?php
/**
 * Navegación del portal tenant — INTELLIHELP
 *
 * El markup conserva las clases `ih-sidebar*` porque así lo llaman las 12
 * pantallas que arman la página, pero assets/css/app.css lo dibuja como una
 * fila de pestañas debajo del topbar: la misma silueta que el CRM y APP Tickets.
 *
 * Variables esperadas:
 *   $page_section string
 *   $tenant_name  string (nombre empresa actual)
 */
$page_section = $page_section ?? '';
$tenant_name  = $tenant_name  ?? 'Mi empresa';
if (!function_exists('ih_tn_active')) {
function ih_tn_active(string $key, string $current): string {
    return $key === $current ? ' is-active' : '';
}
}
require_once __DIR__ . '/../cloud_icons.php';
require_once __DIR__ . '/../../core/security/permissions.php';
$sidebar_cloud_provider = isset($conn) && $conn instanceof mysqli ? cloud_provider_actual($conn) : 'google';
$sidebar_cloud_label    = strtolower($sidebar_cloud_provider) === 'microsoft' ? 'OneDrive' : 'Drive';

// Permisos del usuario actual (rol en sesion)
$sb_role = (string)($_SESSION['role'] ?? 'viewer');
$sb_can_create   = role_can('asset.create', $sb_role);
$sb_can_report   = role_can('report.view', $sb_role);
$sb_can_export   = role_can('asset.export', $sb_role);
$sb_can_import   = role_can('asset.import_csv', $sb_role) || role_can('asset.backup_full', $sb_role);
$sb_can_userman  = role_can('user.view', $sb_role) || role_can('user.edit', $sb_role);
$sb_can_intedit  = role_can('integration.edit', $sb_role);
$sb_can_catalog  = role_can('catalog.manage', $sb_role);
$sb_can_trash    = role_can('asset.trash.view', $sb_role);
?>
<nav class="ih-sidebar ih-app__sidebar" aria-label="Secciones">
  <div class="ih-sidebar__nav">
    <div class="ih-sidebar__group">
      <a href="/inventario/index.php" class="ih-sidebar__link<?= ih_tn_active('inventario',$page_section) ?>"><i class="bi bi-box-seam"></i> Activos</a>
      <?php if ($sb_can_create): ?>
        <a href="/inventario/agregar.php" class="ih-sidebar__link<?= ih_tn_active('agregar',$page_section) ?>"><i class="bi bi-plus-square"></i> Nuevo</a>
      <?php endif; ?>
      <?php if ($sb_can_trash): ?>
        <a href="/inventario/archivados.php" class="ih-sidebar__link<?= ih_tn_active('archivados',$page_section) ?>"><i class="bi bi-archive"></i> Papelera</a>
      <?php endif; ?>
      <?php if ($sb_can_report): ?>
        <a href="/inventario/reportes.php" class="ih-sidebar__link<?= ih_tn_active('reportes',$page_section) ?>"><i class="bi bi-graph-up"></i> Reportes</a>
      <?php endif; ?>
      <?php if ($sb_can_export): ?>
        <a href="/inventario/exportar.php" class="ih-sidebar__link<?= ih_tn_active('exportar',$page_section) ?>"><i class="bi bi-download"></i> Exportar</a>
      <?php endif; ?>
      <?php if ($sb_can_import): ?>
        <a href="/inventario/importar.php" class="ih-sidebar__link<?= ih_tn_active('importar',$page_section) ?>"><i class="bi bi-upload"></i> Importar</a>
      <?php endif; ?>
      <?php if ($sb_can_catalog): ?>
        <a href="/inventario/catalogos.php" class="ih-sidebar__link<?= ih_tn_active('catalogos',$page_section) ?>"><i class="bi bi-list-ul"></i> Listas</a>
        <a href="/inventario/campos.php" class="ih-sidebar__link<?= ih_tn_active('campos',$page_section) ?>"><i class="bi bi-sliders"></i> Campos</a>
      <?php endif; ?>
      <?php if ($sb_can_intedit): ?>
        <a href="/inventario/configurar_integracion.php" class="ih-sidebar__link<?= ih_tn_active('integracion',$page_section) ?>">
          <?= cloud_icon_svg($sidebar_cloud_provider, 16) ?> <?= htmlspecialchars($sidebar_cloud_label) ?>
        </a>
      <?php endif; ?>
      <?php if ($sb_can_userman): ?>
        <a href="/inventario/usuarios.php" class="ih-sidebar__link<?= ih_tn_active('usuarios',$page_section) ?>"><i class="bi bi-people"></i> Usuarios</a>
        <a href="/inventario/roles.php" class="ih-sidebar__link<?= ih_tn_active('roles',$page_section) ?>"><i class="bi bi-shield-lock"></i> Roles</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="ih-sidebar__group" style="margin-left:auto">
    <a href="/auth/change_password.php" class="ih-sidebar__link" title="Cambiar contraseña"><i class="bi bi-key"></i><span class="ih-hidden-mobile"> Contraseña</span></a>
    <a href="/auth/logout.php" class="ih-sidebar__link" title="Cerrar sesión"><i class="bi bi-box-arrow-right"></i><span class="ih-hidden-mobile"> Salir</span></a>
  </div>
</nav>
