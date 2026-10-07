<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_guard.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
if (is_file(__DIR__ . '/../logs/logger.php')) { require_once __DIR__ . '/../logs/logger.php'; }
if (!function_exists('log_event')) {
    function log_event($origin, $type, $msg, $tag = 'app') {}
}

$db = new MasterDB();
$companies = [];
$res = $db->pquery('SELECT id, nombre, razon_social FROM companies WHERE active = 1 ORDER BY nombre ASC');
$rs = $res->get_result();
while ($row = $rs->fetch_assoc()) { $companies[] = $row; }

$csrf_token = generateCsrfToken('select_empresa');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ─────────────────────────────────────────────────────────────────────────
    // Esta página era una escalada de privilegios entre empresas.
    //
    // No pedía sesión ni token: aceptaba `company_id` por POST, verificaba sólo
    // que la empresa estuviera ACTIVA —nunca que el usuario perteneciera a
    // ella— y escribía $_SESSION['company_id'] dejando $_SESSION['user_id']
    // intacto. A partir de ahí tn() resuelve contra las tablas de la empresa
    // nueva, e inventario/index.php hace `SELECT ... FROM <nuevo>.users WHERE
    // id = $user_id`. Si existe una fila con ese mismo id numérico, el usuario
    // QUEDA LOGUEADO COMO ESA PERSONA, con su rol. El usuario 1 de una empresa
    // entraba como el usuario 1 de otra — que en un tenant recién creado es el
    // admin que genera dashboard_empresas.php.
    //
    // El arreglo no es sólo el CSRF: es que elegir empresa no puede conservar
    // una identidad de otra. Un usuario del tenant es una fila en la tabla
    // `users` de SU empresa; "tener acceso a dos empresas" son dos cuentas
    // distintas, no una que viaja. Así que acá se fija la empresa, se descarta
    // toda la autenticación y se manda al login de esa empresa.
    // ─────────────────────────────────────────────────────────────────────────
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '', 'select_empresa')) {
        log_event('select_empresa', 'CSRF_BLOCK', 'Token inválido al elegir empresa', 'seguridad');
        http_response_code(403);
        echo 'Formulario expirado. Volvé a cargar la página.';
        exit;
    }

    $cid   = (int)($_POST['company_id'] ?? 0);
    $valida = false;
    foreach ($companies as $c) {
        if ((int)$c['id'] === $cid) { $valida = true; break; }
    }

    if (!$valida) {
        log_event('select_empresa', 'AUTH_FAIL', "company_id inválido o inactivo: {$cid}", 'seguridad');
        header('Location: /dashboard/select_empresa.php');
        exit;
    }

    // Descartar la sesión entera y quedarse sólo con la empresa elegida.
    // app_session_rotar() además regenera el ID, así que no sobrevive nada del
    // contexto anterior: ni user_id, ni role, ni access_token de Drive, ni el
    // flag de cambio de contraseña obligatorio.
    app_session_rotar([]);
    $_SESSION['company_id'] = $cid;

    app_session_cerrar_y_redirigir(tenant_login_url($cid));
}

/* Esta pantalla se escribía su propio <head> a mano: cargaba la hoja del panel
   y nada más. Le faltaban la hoja de componentes compartida —por eso el <style>
   de abajo volvía a dibujar una tarjeta que ya existía—, la versión de los
   assets (un cambio de CSS quedaba tapado por la caché del navegador y la del
   CDN hasta un Ctrl+F5) y la tipografía Saira, así que salía en la fuente del
   sistema. Usa el encabezado del sistema, como las otras veinte pantallas. */
$page_title  = 'Elegí tu empresa';
$page_portal = 'superadmin';
require __DIR__ . '/../includes/layout/head.php';
?>
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
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="company_id" id="company_id">
          <div class="ih-hub ih-hub--scroll">
            <?php foreach ($companies as $c): ?>
              <?php
                $initials = '';
                foreach (preg_split('/\s+/', (string)$c['nombre']) as $p) {
                    if (strlen($initials) < 2) $initials .= strtoupper(substr((string)$p, 0, 1));
                }
              ?>
              <button type="button" class="ih-hub__item"
                      onclick="document.getElementById('company_id').value=<?= (int)$c['id'] ?>;document.getElementById('form-empresa').submit()">
                <span class="ih-hub__icono ih-hub__icono--iniciales"><?= htmlspecialchars($initials ?: '·') ?></span>
                <span class="ih-hub__texto">
                  <span class="ih-hub__titulo"><?= htmlspecialchars($c['nombre']) ?></span>
                  <?php if (!empty($c['razon_social'])): ?>
                    <span class="ih-hub__desc"><?= htmlspecialchars($c['razon_social']) ?></span>
                  <?php endif; ?>
                </span>
                <i class="bi bi-chevron-right ih-hub__flecha" aria-hidden="true"></i>
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
<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
