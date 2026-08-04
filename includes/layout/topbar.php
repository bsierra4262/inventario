<?php
/**
 * Topbar reutilizable — INTELLIHELP
 *
 * Variables esperadas:
 *   $topbar_title    string — texto principal del topbar
 *   $topbar_subtitle string
 *   $topbar_user     string — nombre del usuario logueado (para el chip)
 *   $topbar_email    string — email del usuario
 *   $topbar_actions  string — HTML extra de botones a la derecha
 *   $tenant_name     string — nombre de la empresa (marca del portal tenant)
 */
$topbar_title    = $topbar_title    ?? '';
$topbar_subtitle = $topbar_subtitle ?? '';
$topbar_user     = $topbar_user     ?? '';
$topbar_email    = $topbar_email    ?? '';
$topbar_actions  = $topbar_actions  ?? '';
$tenant_name     = $tenant_name     ?? '';
// Foto de perfil: superadmin (Google SSO) o usuario tenant (Google Drive OAuth)
$topbar_pic = $topbar_pic ?? $_SESSION['superadmin_pic'] ?? $_SESSION['user_picture'] ?? '';
$initials = '';
if ($topbar_user) {
    $parts = preg_split('/\s+/', trim($topbar_user));
    foreach ($parts as $p) { if ($initials === '' || strlen($initials) < 2) $initials .= strtoupper(substr($p, 0, 1)); }
}
?>
<style>
.ih-user-chip__photo { width:28px; height:28px; border-radius:50%; object-fit:cover; display:block; flex-shrink:0; }
.ih-topbar__brand { display:flex; align-items:center; gap:.6rem; flex-shrink:0; text-decoration:none; color:inherit; }
.ih-topbar__brand:hover { text-decoration:none; }
.ih-topbar__brand-logo { width:32px; height:32px; border-radius:9px; overflow:hidden; flex:none;
  background:var(--primary,#663399); color:#fff; display:flex; align-items:center; justify-content:center;
  font-weight:800; font-size:15px; }
.ih-topbar__brand-text { line-height:1.15; }
.ih-topbar__brand-text strong { display:block; font-size:13.5px; font-weight:800; letter-spacing:-.02em; }
.ih-topbar__brand-text span { display:block; font-size:11px; color:var(--muted,#6f6a7d); }
.ih-topbar__sep { width:1px; height:26px; background:var(--line,#e7e4ef); flex:none; }
@media (max-width:640px){ .ih-topbar__brand-text, .ih-topbar__sep { display:none; } }
</style>
<header class="ih-topbar ih-app__topbar">
  <a class="ih-topbar__brand" href="/inventario/index.php" title="Ir al inventario">
    <span class="ih-topbar__brand-logo"><?= platform_logo_html() ?></span>
    <span class="ih-topbar__brand-text">
      <strong>Asset Vault</strong>
      <span><?= htmlspecialchars($tenant_name ?: 'INTELLIHELP') ?></span>
    </span>
  </a>

  <span class="ih-topbar__sep"></span>

  <div style="min-width:0">
    <h1 class="ih-topbar__title">
      <?= htmlspecialchars($topbar_title) ?>
      <?php if ($topbar_subtitle): ?><small><?= htmlspecialchars($topbar_subtitle) ?></small><?php endif; ?>
    </h1>
  </div>

  <div class="ih-topbar__spacer"></div>

  <div class="ih-topbar__actions">
    <?= $topbar_actions ?>
    <?php if ($topbar_user): ?>
      <div class="ih-user-chip" title="<?= htmlspecialchars($topbar_email ?: $topbar_user) ?>">
        <?php if ($topbar_pic): ?>
          <img src="<?= htmlspecialchars($topbar_pic) ?>" alt="" class="ih-user-chip__photo" referrerpolicy="no-referrer">
        <?php else: ?>
          <span class="ih-user-chip__avatar"><?= htmlspecialchars($initials ?: '·') ?></span>
        <?php endif; ?>
        <span class="ih-hidden-mobile"><?= htmlspecialchars($topbar_user) ?></span>
      </div>
    <?php endif; ?>
  </div>
</header>
