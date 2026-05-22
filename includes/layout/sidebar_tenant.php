<?php
/**
 * Sidebar del inventario (tenant) — INTELLIHELP
 * Variables esperadas:
 *   $page_section string
 *   $tenant_name  string (nombre empresa actual)
 */
$page_section = $page_section ?? '';
$tenant_name  = $tenant_name  ?? 'Mi empresa';
function ih_tn_active(string $key, string $current): string {
    return $key === $current ? ' is-active' : '';
}
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
      <a href="/inventario/index.php"           class="ih-sidebar__link<?= ih_tn_active('inventario',$page_section) ?>"><i class="bi bi-box-seam"></i>     Activos</a>
      <a href="/inventario/agregar.php"         class="ih-sidebar__link<?= ih_tn_active('agregar',   $page_section) ?>"><i class="bi bi-plus-square"></i> Agregar activo</a>
      <a href="/inventario/reportes.php"        class="ih-sidebar__link<?= ih_tn_active('reportes',  $page_section) ?>"><i class="bi bi-graph-up"></i>    Reportes</a>
      <a href="/inventario/exportar.php"        class="ih-sidebar__link<?= ih_tn_active('exportar',  $page_section) ?>"><i class="bi bi-download"></i>    Exportar CSV</a>
      <a href="/inventario/importar.php"        class="ih-sidebar__link<?= ih_tn_active('importar',  $page_section) ?>"><i class="bi bi-upload"></i>      Importar CSV</a>
    </div>

    <div class="ih-sidebar__group">
      <div class="ih-sidebar__group-title">Cuenta</div>
      <a href="/change_password.php" class="ih-sidebar__link"><i class="bi bi-key"></i> Cambiar contraseña</a>
      <a href="/logout.php"          class="ih-sidebar__link"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
    </div>
  </nav>

  <div class="ih-sidebar__footer">
    © <?= date('Y') ?> INTELLIHELP S.A.<br>
    Powered by Asset Vault
  </div>
</aside>
