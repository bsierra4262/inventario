<?php
/**
 * Sidebar del inventario (tenant) — INTELLIHELP
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
$sidebar_cloud_label    = strtolower($sidebar_cloud_provider) === 'microsoft' ? 'OneDrive / Cloud' : 'Google Drive / Cloud';

// Permisos del usuario actual (rol en sesion)
$sb_role = (string)($_SESSION['role'] ?? 'viewer');
$sb_can_create   = role_can('asset.create', $sb_role);
$sb_can_report   = role_can('report.view', $sb_role);
$sb_can_export   = role_can('asset.export', $sb_role);
$sb_can_import   = role_can('asset.import_csv', $sb_role) || role_can('asset.backup_full', $sb_role);
$sb_can_userman  = role_can('user.view', $sb_role) || role_can('user.edit', $sb_role);
$sb_can_intedit  = role_can('integration.edit', $sb_role);
?>
<aside class="ih-sidebar ih-app__sidebar">
  <a href="/inventario/index.php" class="ih-sidebar__brand">
    <span class="ih-sidebar__logo"><?= platform_logo_html() ?></span>
    <span class="ih-sidebar__brand-text">
      <strong>Asset Vault</strong>
      <span><?= htmlspecialchars($tenant_name) ?></span>
    </span>
  </a>

  <nav class="ih-sidebar__nav">
    <div class="ih-sidebar__group">
      <div class="ih-sidebar__group-title">Inventario</div>
      <a href="/inventario/index.php" class="ih-sidebar__link<?= ih_tn_active('inventario',$page_section) ?>"><i class="bi bi-box-seam"></i> Activos</a>
      <?php if ($sb_can_create): ?>
        <a href="/inventario/agregar.php" class="ih-sidebar__link<?= ih_tn_active('agregar',$page_section) ?>"><i class="bi bi-plus-square"></i> Agregar activo</a>
      <?php endif; ?>
      <?php if ($sb_can_report): ?>
        <a href="/inventario/reportes.php" class="ih-sidebar__link<?= ih_tn_active('reportes',$page_section) ?>"><i class="bi bi-graph-up"></i> Reportes</a>
      <?php endif; ?>
      <?php if ($sb_can_export): ?>
        <a href="/inventario/exportar.php" class="ih-sidebar__link<?= ih_tn_active('exportar',$page_section) ?>"><i class="bi bi-download"></i> Exportar CSV</a>
      <?php endif; ?>
      <?php if ($sb_can_import): ?>
        <a href="/inventario/importar.php" class="ih-sidebar__link<?= ih_tn_active('importar',$page_section) ?>"><i class="bi bi-upload"></i> Importar CSV</a>
      <?php endif; ?>
    </div>

    <?php if ($sb_can_intedit || $sb_can_userman): ?>
    <div class="ih-sidebar__group">
      <div class="ih-sidebar__group-title">Configuración</div>
      <?php if ($sb_can_intedit): ?>
      <a href="/inventario/configurar_integracion.php" class="ih-sidebar__link<?= ih_tn_active('integracion',$page_section) ?>" style="display:flex;align-items:center;gap:8px;">
        <?= cloud_icon_svg($sidebar_cloud_provider, 18) ?>
        <span><?= htmlspecialchars($sidebar_cloud_label) ?></span>
      </a>
      <?php endif; ?>
      <?php if ($sb_can_userman): ?>
      <a href="/inventario/usuarios.php" class="ih-sidebar__link<?= ih_tn_active('usuarios',$page_section) ?>">
        <i class="bi bi-people"></i> Usuarios
      </a>
      <a href="/inventario/roles.php" class="ih-sidebar__link<?= ih_tn_active('roles',$page_section) ?>">
        <i class="bi bi-shield-lock"></i> Roles y permisos
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="ih-sidebar__group">
      <div class="ih-sidebar__group-title">Cuenta</div>
      <a href="/auth/change_password.php" class="ih-sidebar__link"><i class="bi bi-key"></i> Cambiar contraseña</a>
      <a href="/auth/logout.php"          class="ih-sidebar__link"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
    </div>
  </nav>

  <div class="ih-sidebar__footer">
    © <?= date('Y') ?> INTELLIHELP S.A.<br>
    Powered by Asset Vault
  </div>
</aside>
