<?php
/**
 * Sidebar del panel SuperAdmin — INTELLIHELP
 * Variables esperadas:
 *   $page_section string — clave de sección activa
 */
$page_section = $page_section ?? '';
function ih_sa_active(string $key, string $current): string {
    return $key === $current ? ' is-active' : '';
}
?>
<aside class="ih-sidebar ih-app__sidebar">
  <a href="/dashboard/dashboard_superadmin.php" class="ih-sidebar__brand">
    <span class="ih-sidebar__logo">i</span>
    <span class="ih-sidebar__brand-text">
      <strong>Asset Vault</strong>
      <span>INTELLIHELP · SuperAdmin</span>
    </span>
  </a>

  <nav class="ih-sidebar__nav">
    <div class="ih-sidebar__group">
      <div class="ih-sidebar__group-title">Plataforma</div>
      <a href="/dashboard/dashboard_superadmin.php" class="ih-sidebar__link<?= ih_sa_active('home',     $page_section) ?>"><i class="bi bi-speedometer2"></i> Resumen</a>
      <a href="/dashboard/dashboard_superadmin.php" class="ih-sidebar__link<?= ih_sa_active('empresas', $page_section) ?>"><i class="bi bi-buildings"></i>     Empresas</a>
      <a href="/dashboard/dashboard_empresas.php"   class="ih-sidebar__link<?= ih_sa_active('nueva',    $page_section) ?>"><i class="bi bi-plus-square"></i>  Nueva empresa</a>
    </div>

    <div class="ih-sidebar__group">
      <div class="ih-sidebar__group-title">Administración</div>
      <a href="/dashboard/superadmin_usuarios.php"      class="ih-sidebar__link<?= ih_sa_active('users',       $page_section) ?>"><i class="bi bi-people"></i>      Usuarios plataforma</a>
      <a href="/dashboard/superadmin_integraciones.php" class="ih-sidebar__link<?= ih_sa_active('integrations',$page_section) ?>"><i class="bi bi-plug"></i>        Integraciones</a>
      <a href="/dashboard/superadmin_logs.php"          class="ih-sidebar__link<?= ih_sa_active('logs',        $page_section) ?>"><i class="bi bi-list-ul"></i>     Auditoría</a>
    </div>

    <div class="ih-sidebar__group">
      <div class="ih-sidebar__group-title">Cuenta</div>
      <a href="/auth/superadmin_logout.php" class="ih-sidebar__link"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
    </div>
  </nav>

  <div class="ih-sidebar__footer">
    © <?= date('Y') ?> INTELLIHELP S.A.<br>
    Asset Vault · Plataforma SaaS
  </div>
</aside>
