<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/config/platform.php';

$db = new MasterDB();
$companies = [];
$res = $db->pquery('SELECT id, nombre, razon_social FROM companies WHERE active = 1 ORDER BY nombre ASC');
$rs = $res->get_result();
while ($row = $rs->fetch_assoc()) { $companies[] = $row; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid = (int)($_POST['company_id'] ?? 0);
    foreach ($companies as $c) {
        if ((int)$c['id'] === $cid) {
            $_SESSION['company_id'] = $cid;
            break;
        }
    }
    $target = $_SESSION['pending_target'] ?? '/inventario/index.php';
    unset($_SESSION['pending_target']);
    header('Location: ' . $target);
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#663399">
<title>Elegí tu empresa · Asset Vault · INTELLIHELP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/css/intellihelp.css" rel="stylesheet">
<style>
  .company-list { display: flex; flex-direction: column; gap: 8px; max-height: 360px; overflow-y: auto; padding: 4px; margin: 4px -4px; }
  .company-item { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 1px solid #E4E4E7; border-radius: 12px; background: #fff; cursor: pointer; transition: all .15s; text-align: left; width: 100%; font-family: 'Saira', sans-serif; }
  .company-item:hover { border-color: #663399; background: #F4F1F9; }
  .company-item__avatar { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #663399, #4E247A); color: #fff; font-weight: 700; font-size: 16px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .company-item__name { font-weight: 600; color: #18181B; font-size: 14px; }
  .company-item__razon { font-size: 12px; color: #71717A; margin-top: 2px; }
  .company-item__arrow { margin-left: auto; color: #A1A1AA; transition: all .15s; }
  .company-item:hover .company-item__arrow { color: #663399; transform: translateX(3px); }
</style>
</head>
<body>
<div class="ih-auth">
  <div class="ih-auth__card ih-anim-fade">
    <div class="ih-auth__header">
      <div class="ih-auth__brand">
        <div class="ih-auth__logo"><?= platform_logo_html() ?></div>
        <div class="ih-auth__brand-text">
          <strong>INTELLIHELP</strong>
          <span>Asset Vault</span>
        </div>
      </div>
      <h2 class="ih-auth__title">Elegí tu empresa</h2>
      <p class="ih-auth__subtitle">Tenés acceso a más de un tenant</p>
    </div>

    <div class="ih-auth__body">
      <?php if (empty($companies)): ?>
        <div class="ih-alert ih-alert--warning">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div>No hay empresas activas. Contactá al superadmin para que active al menos una.</div>
        </div>
      <?php else: ?>
        <form method="post" id="form-empresa">
          <input type="hidden" name="company_id" id="company_id">
          <div class="company-list">
            <?php foreach ($companies as $c): ?>
              <?php
                $initials = '';
                foreach (preg_split('/\s+/', (string)$c['nombre']) as $p) {
                    if (strlen($initials) < 2) $initials .= strtoupper(substr((string)$p, 0, 1));
                }
              ?>
              <button type="button" class="company-item"
                      onclick="document.getElementById('company_id').value=<?= (int)$c['id'] ?>;document.getElementById('form-empresa').submit()">
                <span class="company-item__avatar"><?= htmlspecialchars($initials ?: '·') ?></span>
                <div>
                  <div class="company-item__name"><?= htmlspecialchars($c['nombre']) ?></div>
                  <?php if (!empty($c['razon_social'])): ?>
                    <div class="company-item__razon"><?= htmlspecialchars($c['razon_social']) ?></div>
                  <?php endif; ?>
                </div>
                <i class="bi bi-arrow-right company-item__arrow"></i>
              </button>
            <?php endforeach; ?>
          </div>
        </form>
      <?php endif; ?>

      <div class="ih-mt-4 ih-text-center">
        <a href="/auth/login.php" class="ih-text-sm ih-text-muted">
          <i class="bi bi-arrow-left"></i> Volver al login
        </a>
      </div>
    </div>

    <div class="ih-auth__footer">
      © <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault
    </div>
  </div>
</div>
</body>
</html>
