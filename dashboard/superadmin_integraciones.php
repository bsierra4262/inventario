<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/db/master_connection.php';

superadmin_require_login();
if (!platform_can('integrations.manage')) {
    header('Location: /dashboard/dashboard_resumen.php');
    exit;
}
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

// URI de callback único (solo lectura, el admin la copia en su consola)
$callbackUri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/superadmin_callback.php';

// ── POST: guardar configuración ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    try {
        $values = [
            // Google
            'sa_google_client_id'     => trim($_POST['sa_google_client_id']     ?? ''),
            'sa_google_client_secret' => trim($_POST['sa_google_client_secret'] ?? ''),
            'sa_google_hd'            => strtolower(trim($_POST['sa_google_hd']  ?? '')),
            // Microsoft
            'sa_ms_client_id'         => trim($_POST['sa_ms_client_id']          ?? ''),
            'sa_ms_client_secret'     => trim($_POST['sa_ms_client_secret']       ?? ''),
            'sa_ms_tenant_id'         => trim($_POST['sa_ms_tenant_id']           ?? ''),
            'sa_ms_hd'                => strtolower(trim($_POST['sa_ms_hd']       ?? '')),
        ];

        $stmt = $db->conn->prepare(
            "INSERT INTO platform_config (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        );
        foreach ($values as $k => $v) {
            // No pisar secrets vacíos (el admin dejó el campo en blanco = sin cambio)
            if ($v === '' && in_array($k, ['sa_google_client_secret', 'sa_ms_client_secret'], true)) continue;
            $stmt->bind_param('ss', $k, $v);
            $stmt->execute();
        }
        $stmt->close();

        $ok = 'Configuración guardada correctamente.';

        // Si el admin venía forzado a configurar SSO, verificar si quedó algo configurado
        if (!empty($_SESSION['pending_sso_setup'])) {
            $reloaded = [];
            $res = $db->conn->query("SELECT config_key, config_value FROM platform_config WHERE config_key IN (
                'sa_google_client_id','sa_google_client_secret','sa_ms_client_id','sa_ms_client_secret'
            )");
            if ($res) { while ($r = $res->fetch_assoc()) { $reloaded[$r['config_key']] = $r['config_value']; } }

            $googleOk = ($reloaded['sa_google_client_id'] ?? '') !== '' && ($reloaded['sa_google_client_secret'] ?? '') !== '';
            $msOk     = ($reloaded['sa_ms_client_id']     ?? '') !== '' && ($reloaded['sa_ms_client_secret']     ?? '') !== '';

            if ($googleOk || $msOk) {
                // SSO configurado → cerrar sesión y redirigir al login SSO
                unset($_SESSION['pending_sso_setup']);
                session_destroy();
                header('Location: /auth/superadmin_login.php?sso_configured=1');
                exit;
            }
        }

    } catch (Throwable $e) {
        $err = 'Error guardando configuración: ' . $e->getMessage();
    }
}

// ── Cargar valores actuales ───────────────────────────────────────────────────
$current = [
    'sa_google_client_id'     => '',
    'sa_google_client_secret' => '',
    'sa_google_hd'            => '',
    'sa_ms_client_id'         => '',
    'sa_ms_client_secret'     => '',
    'sa_ms_tenant_id'         => '',
    'sa_ms_hd'                => '',
];
$res = $db->conn->query("SELECT config_key, config_value FROM platform_config WHERE config_key LIKE 'sa_%'");
if ($res) { while ($r = $res->fetch_assoc()) { $current[$r['config_key']] = $r['config_value']; } }

$pendingSetup = !empty($_SESSION['pending_sso_setup']);

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

    <?php if ($pendingSetup): ?>
      <div class="ih-alert ih-alert--warning" style="margin-bottom:20px">
        <i class="bi bi-shield-exclamation" style="font-size:22px;flex-shrink:0"></i>
        <div>
          <div class="ih-alert__title">Configuración de SSO requerida</div>
          Por seguridad, el acceso con usuario y contraseña es solo temporal.<br>
          Configurá <strong>Google Workspace</strong> o <strong>Microsoft 365</strong> como método de autenticación.<br>
          Una vez guardado, tu sesión se cerrará y deberás ingresar con tu cuenta corporativa.
        </div>
      </div>
    <?php endif; ?>

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

    <!-- URI de callback (solo lectura) -->
    <div class="ih-card" style="margin-bottom:20px">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-link-45deg"></i> URI de Callback OAuth</h2>
          <div class="ih-card__subtitle">Registrá esta URI única en Google Cloud Console y en Azure — aplica para ambos proveedores</div>
        </div>
      </div>
      <div class="ih-card__body">
        <div class="ih-flex ih-items-center ih-gap-2">
          <code class="ih-input" style="background:#f7f2ff;font-size:13px;padding:10px 14px;flex:1;cursor:text;word-break:break-all" id="callback-uri-display"><?= htmlspecialchars($callbackUri) ?></code>
          <button type="button" class="ih-btn ih-btn--outline ih-btn--sm" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($callbackUri, ENT_QUOTES) ?>');this.innerHTML='<i class=\'bi bi-check2\'></i> Copiado'">
            <i class="bi bi-clipboard"></i> Copiar
          </button>
        </div>
        <p class="ih-text-xs ih-text-muted" style="margin-top:8px">
          <i class="bi bi-info-circle"></i>
          En <strong>Google Console</strong>: Credentials → OAuth client → Authorized redirect URIs.<br>
          En <strong>Azure</strong>: App registrations → Authentication → Redirect URIs.
        </p>
      </div>
    </div>

    <form method="post" autocomplete="off">
      <?php csrf_input(); ?>

      <!-- ── Google Workspace SSO ────────────────────────────────────────── -->
      <div class="ih-card" style="margin-bottom:20px">
        <div class="ih-card__header">
          <div>
            <h2 class="ih-card__title"><i class="bi bi-google" style="color:#4285F4"></i> Google Workspace SSO</h2>
            <div class="ih-card__subtitle">Permite ingresar con una cuenta corporativa de Google</div>
          </div>
          <?php if ($current['sa_google_client_id'] !== ''): ?>
            <span class="ih-badge ih-badge--success"><i class="bi bi-check-circle"></i> Configurado</span>
          <?php else: ?>
            <span class="ih-badge ih-badge--warning">Sin configurar</span>
          <?php endif; ?>
        </div>
        <div class="ih-card__body">
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
                <input class="ih-input" name="sa_google_client_secret" type="password"
                  placeholder="<?= $current['sa_google_client_secret'] ? '(configurado — dejá vacío para no cambiar)' : 'GOCSPX-...' ?>">
              </div>
            </div>
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Dominio Workspace <span class="ih-text-muted">(opcional)</span></label>
                <input class="ih-input" name="sa_google_hd" value="<?= htmlspecialchars($current['sa_google_hd']) ?>" placeholder="intellihelp.tech">
                <div class="ih-field__hint">Solo cuentas de ese dominio podrán acceder.</div>
              </div>
            </div>
          </div>
          <details class="ih-mt-4" style="font-size:13px;color:#555">
            <summary style="cursor:pointer;color:#663399;font-weight:600">¿Cómo configurarlo? <i class="bi bi-chevron-down"></i></summary>
            <ol style="padding-left:1.2rem;line-height:1.9;margin-top:8px">
              <li>Abrí <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> → <em>APIs &amp; Services</em> → <em>OAuth consent screen</em> (elegí "Internal" para Workspace).</li>
              <li><em>Credentials</em> → <em>Create credentials</em> → <em>OAuth client ID</em> → tipo <strong>Web application</strong>.</li>
              <li>En <em>Authorized redirect URIs</em> pegá la URI de callback que aparece arriba.</li>
              <li>Copiá el <em>Client ID</em> y <em>Client Secret</em> acá.</li>
            </ol>
          </details>
        </div>
      </div>

      <!-- ── Microsoft 365 SSO ──────────────────────────────────────────── -->
      <div class="ih-card" style="margin-bottom:20px">
        <div class="ih-card__header">
          <div>
            <h2 class="ih-card__title"><i class="bi bi-microsoft" style="color:#00A4EF"></i> Microsoft 365 / Azure AD SSO</h2>
            <div class="ih-card__subtitle">Permite ingresar con una cuenta corporativa de Microsoft</div>
          </div>
          <?php if ($current['sa_ms_client_id'] !== ''): ?>
            <span class="ih-badge ih-badge--success"><i class="bi bi-check-circle"></i> Configurado</span>
          <?php else: ?>
            <span class="ih-badge ih-badge--warning">Sin configurar</span>
          <?php endif; ?>
        </div>
        <div class="ih-card__body">
          <div class="ih-grid">
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Application (Client) ID</label>
                <input class="ih-input" name="sa_ms_client_id" value="<?= htmlspecialchars($current['sa_ms_client_id']) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
              </div>
            </div>
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Client Secret</label>
                <input class="ih-input" name="sa_ms_client_secret" type="password"
                  placeholder="<?= $current['sa_ms_client_secret'] ? '(configurado — dejá vacío para no cambiar)' : 'xxxxxxxx~xxxxxxx' ?>">
              </div>
            </div>
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Tenant ID <span class="ih-text-muted">(opcional)</span></label>
                <input class="ih-input" name="sa_ms_tenant_id" value="<?= htmlspecialchars($current['sa_ms_tenant_id']) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx o 'common'">
                <div class="ih-field__hint">Dejá vacío para aceptar cualquier cuenta Microsoft (multi-tenant). Ponés tu Tenant ID para restringir a tu organización.</div>
              </div>
            </div>
            <div class="ih-col-6">
              <div class="ih-field">
                <label class="ih-field__label">Dominio requerido <span class="ih-text-muted">(opcional)</span></label>
                <input class="ih-input" name="sa_ms_hd" value="<?= htmlspecialchars($current['sa_ms_hd']) ?>" placeholder="intellihelp.tech">
                <div class="ih-field__hint">Solo cuentas de ese dominio podrán acceder.</div>
              </div>
            </div>
          </div>
          <details class="ih-mt-4" style="font-size:13px;color:#555">
            <summary style="cursor:pointer;color:#663399;font-weight:600">¿Cómo configurarlo? <i class="bi bi-chevron-down"></i></summary>
            <ol style="padding-left:1.2rem;line-height:1.9;margin-top:8px">
              <li>Abrí <a href="https://portal.azure.com/" target="_blank" rel="noopener">Azure Portal</a> → <em>Azure Active Directory</em> → <em>App registrations</em> → <em>New registration</em>.</li>
              <li>Tipo de cuenta: "Accounts in this organizational directory only" (para una sola organización).</li>
              <li>En <em>Redirect URI</em> elegí "Web" y pegá la URI de callback de arriba.</li>
              <li>Copiá el <em>Application (Client) ID</em> y el <em>Directory (Tenant) ID</em> de la pantalla Overview.</li>
              <li>Andá a <em>Certificates &amp; secrets</em> → <em>New client secret</em>, copiá el valor.</li>
              <li>Asegurate de tener el permiso <code>User.Read</code> en <em>API permissions</em>.</li>
            </ol>
          </details>
        </div>
      </div>

      <div class="ih-flex ih-gap-2 ih-mt-2">
        <button class="ih-btn ih-btn--primary" type="submit">
          <i class="bi bi-check-lg"></i> Guardar configuración
        </button>
        <?php if (!$pendingSetup): ?>
          <a href="/dashboard/dashboard_resumen.php" class="ih-btn ih-btn--ghost">Volver</a>
        <?php endif; ?>
      </div>

    </form>

  </main>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
