<?php
/**
 * Topbar reutilizable — INTELLIHELP
 * Variables esperadas:
 *   $topbar_title    string — texto principal del topbar
 *   $topbar_subtitle string
 *   $topbar_user     string — nombre del usuario logueado (para el chip)
 *   $topbar_email    string — email del usuario
 *   $topbar_actions  string — HTML extra de botones a la derecha
 */
$topbar_title    = $topbar_title    ?? '';
$topbar_subtitle = $topbar_subtitle ?? '';
$topbar_user     = $topbar_user     ?? '';
$topbar_email    = $topbar_email    ?? '';
$topbar_actions  = $topbar_actions  ?? '';
$initials = '';
if ($topbar_user) {
    $parts = preg_split('/\s+/', trim($topbar_user));
    foreach ($parts as $p) { if ($initials === '' || strlen($initials) < 2) $initials .= strtoupper(substr($p, 0, 1)); }
}
?>
<header class="ih-topbar ih-app__topbar">
  <button class="ih-topbar__burger" type="button" aria-label="Abrir menú" onclick="document.querySelector('.ih-app').classList.toggle('is-open')">
    <i class="bi bi-list"></i>
  </button>

  <div>
    <h1 class="ih-topbar__title">
      <?= htmlspecialchars($topbar_title) ?>
      <?php if ($topbar_subtitle): ?><small><?= htmlspecialchars($topbar_subtitle) ?></small><?php endif; ?>
    </h1>
  </div>

  <div class="ih-topbar__spacer"></div>

  <div class="ih-topbar__actions">
    <?= $topbar_actions ?>
    <?php if ($topbar_user): ?>
      <div class="ih-user-chip" title="<?= htmlspecialchars($topbar_email) ?>">
        <span class="ih-user-chip__avatar"><?= htmlspecialchars($initials ?: '·') ?></span>
        <span class="ih-hidden-mobile"><?= htmlspecialchars($topbar_user) ?></span>
      </div>
    <?php endif; ?>
  </div>
</header>

<!-- Backdrop para sidebar mobile -->
<div class="ih-backdrop" onclick="document.querySelector('.ih-app').classList.remove('is-open')"></div>
