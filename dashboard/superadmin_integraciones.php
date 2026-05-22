<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/db/master_connection.php';

superadmin_require_login();
csrf_start();

$db  = new MasterDB();
$err = '';
$ok  = '';

// Asegurar tabla platform_config
$db->conn->query("CREATE TABLE IF NOT EXISTS `platform_config` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key   VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT NOT NULL,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    try {
        $values = [
            'sa_google_client_id'     => trim($_POST['sa_google_client_id']     ?? ''),
            'sa_google_client_secret' => trim($_POST['sa_google_client_secret'] ?? ''),
            'sa_google_redirect_uri'  => trim($_POST['sa_google_redirect_uri']  ?? ''),
            'sa_google_hd'            => trim($_POST['sa_google_hd']            ?? ''), // workspace domain
        ];

        $stmt = $db->conn->prepare(
            "INSERT INTO platform_config (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        );
        foreach ($values as $k => $v) {
            if ($v === '' && in_array($k, ['sa_google_client_secret'], true)) continue; // no pisar secret vacío
            $stmt->bind_param('ss', $k, $v);
            $stmt->execute();
        }
        $stmt->close();
        $ok = 'Configuración guardada correctamente.';
    } catch (Throwable $e) {
        $err = 'Error guardando configuración: ' . $e->getMessage();
    }
}

// Cargar valores actuales
$current = [
    'sa_google_client_id'     => '',
    'sa_google_client_secret' => '',
    'sa_google_redirect_uri'  => '',
    'sa_google_hd'            => '',
];
$res = $db->conn->query("SELECT config_key, config_value FROM platform_config WHERE config_key LIKE 'sa_%'");
if ($res) {
    while ($r = $res->fetch_assoc()) { $current[$r['config_key']] = $r['config_value']; }
}

$defaultRedirect = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/superadmin_login.php';

$page_title    = 'Integraciones';
$page_section  = 'integrations';
$topbar_title  = 'Integraciones';
$topbar_subtitle = 'SSO y APIs de la plataforma';
$topbar_user   = $_SESSION['superadmin_name']  ?? 'SuperAdmin';
$topbar_email  = $_SESSION['superadmin_email'] ?? '';
$topbar_actions = '';

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
  <?php require __DIR__ . '/../includes/layout/sidebar_superadmin.php'; ?>
  <?php require __DIR__ . '/../includes/layout/topbar.php'; ?>

  <main class="ih-app__main ih-anim-fade">

    <?php if ($ok): ?>
      <div class="ih-alert ih-alert--success">
        <i class="bi bi-check-circle-fill"></i>
        <div><?= htmlspecialchars($ok) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="ih-alert ih-alert--danger">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><?= htmlspecialchars($err) ?></div>
      </div>
    <?php endif; ?>

    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-google"></i> Google Workspace SSO — SuperAdmin</h2>
          <div class="ih-card__subtitle">Permite ingresar al panel SuperAdmin con tu cuenta corporativa de Google</div>
        </div>
      </div>
      <div class="ih-card__body">
        <form method="post" autocomplete="off">
          <?php csrf_input(); ?>

          <div class="ih-grid">
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Client ID</label>
                <input class="ih-input" name="sa_google_client_id" value="<?= htmlspecialchars($current['sa_google_client_id']) ?>" placeholder="xxxxxxxxxxxx.apps.googleusercontent.com">
              </div>
            </div>
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Client Secret</label>
                <input class="ih-input" name="sa_google_client_secret" type="password" placeholder="<?= $current['sa_google_client_secret'] ? '(configurado — dejá vacío para no cambiar)' : 'GOCSPX-...' ?>">
              </div>
            </div>
            <div class="ih-col-8">
              <div class="ih-field">
                <label class="ih-field__label">Redirect URI</label>
                <input class="ih-input" name="sa_google_redirect_uri" value="<?= htmlspecialchars($current['sa_google_redirect_uri'] ?: $defaultRedirect) ?>">
                <div class="ih-field__hint">Pegá esta URI exactamente en Google Cloud Console → Credentials → Authorized redirect URIs.</div>
              </div>
            </div>
            <div class="ih-col-4">
              <div class="ih-field">
                <label class="ih-field__label">Dominio Workspace (opcional)</label>
                <input class="ih-input" name="sa_google_hd" value="<?= htmlspecialchars($current['sa_google_hd']) ?>" placeholder="intellihelp.com">
                <div class="ih-field__hint">Restringe el SSO al dominio corporativo.</div>
              </div>
            </div>
          </div>

          <div class="ih-flex ih-gap-2 ih-mt-6">
            <button class="ih-btn ih-btn--primary" type="submit">
              <i class="bi bi-check-lg"></i> Guardar
            </button>
            <a href="/dashboard/dashboard_superadmin.php" class="ih-btn ih-btn--ghost">Volver</a>
          </div>
        </form>
      </div>
    </div>

    <div class="ih-card ih-mt-6">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-info-circle"></i> Cómo configurar Google Workspace SSO</h2>
        </div>
      </div>
      <div class="ih-card__body">
        <ol style="padding-left:1.2rem;line-height:1.8;font-size:14px;">
          <li>Ingresá a <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> con tu cuenta de Workspace.</li>
          <li>Creá un proyecto (o usá uno existente) → <em>APIs &amp; Services</em> → <em>OAuth consent screen</em>.
              Elegí "Internal" si tu Workspace está disponible, sino "External".</li>
          <li>Andá a <em>Credentials</em> → <em>Create credentials</em> → <em>OAuth client ID</em> → tipo <strong>Web application</strong>.</li>
          <li>Agregá la <em>Authorized redirect URI</em> con el valor que aparece arriba en este formulario.</li>
          <li>Copiá el <em>Client ID</em> y <em>Client Secret</em> y pegalos acá.</li>
          <li>Guardá y volvé a <a href="/auth/superadmin_login.php">la pantalla de login</a> — ahora verás "Continuar con Google".</li>
        </ol>
        <p class="ih-text-sm ih-text-muted ih-mt-4">
          <i class="bi bi-shield-check"></i> Tu cuenta Google se vinculará automáticamente con el superadmin <code>bsierra</code>
          la primera vez que ingreses (siempre que el email coincida con el registrado en la plataforma).
        </p>
      </div>
    </div>

  </main>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
