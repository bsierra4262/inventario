<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php'); exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/csrf.php';
require_once __DIR__ . '/../logs/logger.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../includes/hub.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0))); exit;
}

$usersTable = tn('users');
$s = $conn->prepare("SELECT role FROM `{$usersTable}` WHERE id = ?");
$s->bind_param('i', $user_id);
$s->execute();
$me = $s->get_result()->fetch_assoc();
$s->close();
require_permission('integration.edit', $me);

// --- Leer config actual del tenant ---
$configTable = tn('cloud_config');
$googleCfg   = [];
$msCfg       = [];

try {
    $res = $conn->query("SELECT provider, config_key, config_value FROM `{$configTable}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($row['provider'] === 'google') $googleCfg[$row['config_key']] = $row['config_value'];
            else $msCfg[$row['config_key']] = $row['config_value'];
        }
    }
} catch (Throwable) {}

// --- Estado credenciales de plataforma ---
$platformGoogleOk = GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
$platformMsOk     = MS_CLIENT_ID !== '' && MS_CLIENT_SECRET !== '';

$csrf = generateCsrfToken('cloud_config');

// --- Settings del tenant (toggle acceso alternativo, etc) ---
require_once __DIR__ . '/../core/tenants/tenant_settings.php';

// Procesar POST de settings (toggle local login)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tenant_setting'])) {
    $tsName  = (string)$_POST['tenant_setting'];
    $tsValue = isset($_POST['tenant_setting_value']) ? (string)$_POST['tenant_setting_value'] : '0';
    if (verifyCsrfToken($_POST['csrf_token'] ?? '', 'cloud_config')) {
        $allowed = ['disable_local_login'];
        if (in_array($tsName, $allowed, true)) {
            tenant_setting_set($conn, $tsName, $tsValue === '1' ? '1' : '0');
            header('Location: /inventario/configurar_integracion.php?msg=' . urlencode('Configuración actualizada.') . '&type=success');
            exit;
        }
    }
}

$localLoginDisabled = tenant_local_login_disabled($conn);
$feedbackMsg  = htmlspecialchars($_GET['msg']  ?? '');
$feedbackType = match ($_GET['type'] ?? '') {
    'error'   => 'danger',
    'warning' => 'warning',
    default   => 'success',
};

function mask_cfg(string $val): string {
    if (strlen($val) <= 8) return str_repeat('*', strlen($val));
    return substr($val, 0, 4) . str_repeat('*', strlen($val) - 8) . substr($val, -4);
}

// Tenant name
$tenant_name = 'Mi empresa';
try {
    require_once __DIR__ . '/../core/db/master_connection.php';
    $masterDb  = new MasterDB();
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    if ($companyId > 0) {
        $cs = $masterDb->pquery('SELECT nombre FROM companies WHERE id = ? LIMIT 1', 'i', [$companyId]);
        $cr = $cs->get_result()->fetch_assoc();
        if ($cr) $tenant_name = $cr['nombre'];
        $cs->close();
    }
} catch (Throwable) {}

// ── Layout vars ─────────────────────────────────────────────────────────────
$page_title      = 'Integración Cloud — Asset Vault';
$page_section    = 'integracion';
$topbar_title    = 'Integración Cloud';
$topbar_subtitle = 'Google Drive · Microsoft OneDrive';
$topbar_user     = $_SESSION['user_name']    ?? ($_SESSION['user_email'] ?? '');
$topbar_email    = $_SESSION['user_email']   ?? '';
$topbar_pic      = $_SESSION['user_picture'] ?? '';
$topbar_actions  = hub_volver('configuracion');

require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-app">
<?php
require __DIR__ . '/../includes/layout/sidebar_tenant.php';
require __DIR__ . '/../includes/layout/topbar.php';
?>

<main class="ih-app__main">

  <?php if ($feedbackMsg !== ''): ?>
    <div class="ih-alert ih-alert--<?= $feedbackType ?>">
      <i class="bi bi-<?= $feedbackType === 'danger' ? 'exclamation-triangle-fill' : ($feedbackType === 'warning' ? 'exclamation-circle-fill' : 'check-circle-fill') ?>"></i>
      <div><?= $feedbackMsg ?></div>
      <button class="ih-alert__close" type="button" aria-label="Cerrar el aviso"><i class="bi bi-x"></i></button>
    </div>
  <?php endif; ?>

  <!-- ══════════════════ SEGURIDAD: Toggle acceso alternativo ══════════════════ -->
  <?php
  $tenantGoogleHas = !empty($googleCfg['client_id']) && !empty($googleCfg['client_secret']);
  $tenantMsHas     = !empty($msCfg['client_id']) && !empty($msCfg['client_secret']);
  $tenantHasSSO    = $platformGoogleOk || $platformMsOk || $tenantGoogleHas || $tenantMsHas;
  ?>
  <?php if ($tenantHasSSO): ?>
  <div class="ih-card" style="margin-bottom:20px;border-left:4px solid var(--ih-primary);">
    <div class="ih-card__body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
      <div style="flex:1;min-width:280px;">
        <h3 style="margin:0 0 4px;font-size:var(--ih-text-md);color:var(--ih-gray-800);font-weight:700;">
          <i class="bi bi-shield-lock-fill" style="color:var(--ih-primary);"></i>
          Acceso alternativo (usuario + contraseña)
        </h3>
        <p style="margin:0;font-size:var(--ih-text-sm);color:var(--ih-gray-600);">
          <?php if ($localLoginDisabled): ?>
            <strong style="color:var(--ih-danger);">Deshabilitado.</strong>
            Todos los usuarios deben ingresar con Google o Microsoft. El link "acceso alternativo" está oculto en la pantalla de login.
          <?php else: ?>
            <strong style="color:var(--ih-success);">Habilitado.</strong>
            Los usuarios pueden ingresar con usuario y contraseña como fallback de emergencia
            (link <em>··· acceso alternativo</em> visible en la pantalla de login).
          <?php endif; ?>
        </p>
      </div>
      <form method="POST" action="/inventario/configurar_integracion.php" style="display:flex;align-items:center;gap:8px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="tenant_setting" value="disable_local_login">
        <input type="hidden" name="tenant_setting_value" value="<?= $localLoginDisabled ? '0' : '1' ?>">
        <button type="submit" class="ih-btn <?= $localLoginDisabled ? 'ih-btn--success' : 'ih-btn--danger' ?> ih-btn--sm">
          <?php if ($localLoginDisabled): ?>
            <i class="bi bi-unlock-fill"></i> Habilitar acceso alternativo
          <?php else: ?>
            <i class="bi bi-lock-fill"></i> Deshabilitar acceso alternativo
          <?php endif; ?>
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Tabs de proveedor -->
  <div style="display:flex;gap:6px;margin-bottom:20px;padding:6px;background:#fff;border-radius:14px;border:1px solid var(--ih-gray-200);width:fit-content;">
    <button id="tab-google" class="ih-btn ih-btn--primary ih-btn--sm" onclick="showTab('google')">
      <i class="bi bi-google"></i> Google Drive
    </button>
    <button id="tab-microsoft" class="ih-btn ih-btn--ghost ih-btn--sm" onclick="showTab('microsoft')">
      <i class="bi bi-microsoft"></i> Microsoft OneDrive
    </button>
  </div>

  <!-- ══════════════════ GOOGLE ══════════════════ -->
  <div id="panel-google">

    <!-- Estado credenciales: plataforma o tenant -->
    <?php
    $tenantGoogleOk = !empty($googleCfg['client_id']) && !empty($googleCfg['client_secret']);
    ?>
    <?php if ($platformGoogleOk): ?>
      <div class="ih-alert ih-alert--success">
        <i class="bi bi-shield-check-fill"></i>
        <div>
          <strong>Credenciales de plataforma activas.</strong>
          El OAuth de Google está configurado a nivel plataforma — no necesitás ingresar Client ID ni Secret.
          Solo configurá el <strong>ID de carpeta raíz</strong> de Drive para esta empresa.
        </div>
      </div>
    <?php elseif ($tenantGoogleOk): ?>
      <div class="ih-alert ih-alert--success">
        <i class="bi bi-check-circle-fill"></i>
        <div>
          <strong>Credenciales OAuth propias activas.</strong>
          Esta empresa tiene sus credenciales de Google Cloud configuradas. Podés actualizarlas si querés cambiar de proyecto.
        </div>
      </div>
    <?php else: ?>
      <div class="ih-alert ih-alert--warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
          Las credenciales OAuth no están configuradas.
          Podés ingresar las credenciales de tu propia app de Google Cloud, o contactar al administrador del sistema para que las configure a nivel plataforma.
        </div>
      </div>
    <?php endif; ?>

    <div class="ih-card" style="margin-bottom:20px;">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-google" style="color:#EA4335"></i> Google Drive</h2>
          <div class="ih-card__subtitle">
            <?php if (!empty($googleCfg['root_folder_id']) || ($platformGoogleOk && !empty($googleCfg['root_folder_id']))): ?>
              <span class="ih-badge ih-badge--success ih-badge--dot">Carpeta configurada</span>
            <?php elseif ($platformGoogleOk): ?>
              <span class="ih-badge ih-badge--warning">Falta carpeta raíz</span>
            <?php else: ?>
              <span class="ih-badge">Sin configurar</span>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($googleCfg)): ?>
          <form method="POST" action="/backend/cloud_config_crud.php" style="display:inline"
                onsubmit="return confirm('¿Eliminar toda la configuración de Google?')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="clear">
            <input type="hidden" name="provider" value="google">
            <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm" style="color:var(--ih-danger);">
              <i class="bi bi-trash"></i> Limpiar config
            </button>
          </form>
        <?php endif; ?>
      </div>

      <div class="ih-card__body">

        <?php if (!empty($googleCfg)): ?>
          <div style="background:var(--ih-gray-50);border:1px solid var(--ih-gray-200);border-radius:10px;padding:12px 16px;margin-bottom:16px;">
            <div class="ih-text-xs" style="text-transform:uppercase;letter-spacing:.5px;font-weight:700;color:var(--ih-gray-500);margin-bottom:8px;">Configuración actual del tenant</div>
            <?php if (!empty($googleCfg['client_id'])): ?>
              <div class="ih-text-sm ih-mb-1">Client ID: <code><?= htmlspecialchars(mask_cfg($googleCfg['client_id'])) ?></code></div>
            <?php endif; ?>
            <?php if (!empty($googleCfg['root_folder_id'])): ?>
              <div class="ih-text-sm">
                Carpeta raíz Drive: <code><?= htmlspecialchars($googleCfg['root_folder_id']) ?></code>
                <a href="https://drive.google.com/drive/folders/<?= urlencode($googleCfg['root_folder_id']) ?>"
                   target="_blank" rel="noopener" class="ih-text-xs" style="margin-left:6px;color:var(--ih-primary);">
                  <i class="bi bi-box-arrow-up-right"></i> Abrir
                </a>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Si plataforma tiene credenciales: solo pedir root_folder_id -->
        <?php if ($platformGoogleOk): ?>
          <form method="POST" action="/backend/cloud_config_crud.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_google">
            <input type="hidden" name="provider" value="google">
            <input type="hidden" name="google_mode" value="form">
            <!-- No se piden client_id/secret — se usan los de plataforma -->

            <div class="ih-alert ih-alert--info" style="margin-bottom:16px;font-size:13px;">
              <i class="bi bi-folder2-open"></i>
              <div>
                Creá una carpeta en Google Drive para guardar los archivos de esta empresa,
                copiá su ID desde la URL (<code>drive.google.com/drive/folders/<strong>ID_aquí</strong></code>)
                y pegalo abajo.
              </div>
            </div>

            <div class="ih-field">
              <label class="ih-field__label">ID de carpeta raíz en Google Drive <span style="color:var(--ih-danger)">*</span></label>
              <input class="ih-input" type="text" name="google_root_folder" required
                     value="<?= htmlspecialchars($googleCfg['root_folder_id'] ?? '') ?>"
                     placeholder="Ej: 1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms">
              <div class="ih-field__hint">El ID de la carpeta de Drive donde se guardarán los archivos adjuntos.</div>
            </div>

            <button type="submit" class="ih-btn ih-btn--accent" style="margin-top:16px;">
              <i class="bi bi-save"></i> Guardar carpeta Drive
            </button>
          </form>

        <?php else: ?>
          <!-- Sin credenciales de plataforma: permitir ingresar credenciales propias -->
          <div style="display:flex;gap:6px;margin-bottom:14px;">
            <button class="ih-btn ih-btn--primary ih-btn--sm" type="button" id="gjson-btn" onclick="switchGoogleMode('json')">
              <i class="bi bi-file-earmark-code"></i> Pegar JSON
            </button>
            <button class="ih-btn ih-btn--ghost ih-btn--sm" type="button" id="gform-btn" onclick="switchGoogleMode('form')">
              <i class="bi bi-pencil"></i> Ingresar manualmente
            </button>
          </div>

          <form method="POST" action="/backend/cloud_config_crud.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_google">
            <input type="hidden" name="provider" value="google">
            <input type="hidden" name="google_mode" id="google_mode_val" value="json">

            <!-- Modo JSON -->
            <div id="google-json-mode">
              <div class="ih-alert ih-alert--info" style="margin-bottom:12px;font-size:13px;">
                <i class="bi bi-info-circle-fill"></i>
                <div>Descargá el JSON desde <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a> → Credenciales → OAuth 2.0 Client → Descargar JSON.</div>
              </div>
              <div class="ih-field" style="margin-bottom:12px;">
                <label class="ih-field__label">Subir credentials.json</label>
                <input type="file" name="credentials_file" class="ih-input" accept=".json">
              </div>
              <div class="ih-field" style="margin-bottom:12px;">
                <label class="ih-field__label">O pegar el contenido JSON</label>
                <textarea name="credentials_json" class="ih-input" rows="5"
                          style="font-family:monospace;font-size:12px;"
                          placeholder='{"web":{"client_id":"...","client_secret":"...","redirect_uris":[...]}}'></textarea>
              </div>
            </div>

            <!-- Modo Manual -->
            <div id="google-form-mode" style="display:none;">
              <div class="ih-field" style="margin-bottom:12px;">
                <label class="ih-field__label">Client ID <span style="color:var(--ih-danger)">*</span></label>
                <input class="ih-input" type="text" name="google_client_id"
                       value="<?= htmlspecialchars($googleCfg['client_id'] ?? '') ?>"
                       placeholder="xxxxx.apps.googleusercontent.com">
              </div>
              <div class="ih-field" style="margin-bottom:12px;">
                <label class="ih-field__label">Client Secret <span style="color:var(--ih-danger)">*</span></label>
                <input class="ih-input" type="text" name="google_client_secret" placeholder="GOCSPX-...">
              </div>
              <div class="ih-field" style="margin-bottom:12px;">
                <label class="ih-field__label">Redirect URI</label>
                <input class="ih-input" type="url" name="google_redirect_uri"
                       value="<?= htmlspecialchars($googleCfg['redirect_uri'] ?? ((isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'').'/auth/superadmin_callback.php')) ?>">
              </div>
            </div>

            <!-- root_folder_id siempre visible -->
            <div class="ih-field" style="margin-top:14px;">
              <label class="ih-field__label">ID de carpeta raíz en Drive <span style="color:var(--ih-gray-400);font-weight:400;">(opcional)</span></label>
              <input class="ih-input" type="text" name="google_root_folder"
                     value="<?= htmlspecialchars($googleCfg['root_folder_id'] ?? '') ?>"
                     placeholder="1BxiMVs...">
              <div class="ih-field__hint">La carpeta de Drive donde se guardarán los archivos adjuntos de esta empresa.</div>
            </div>

            <button type="submit" class="ih-btn ih-btn--accent" style="margin-top:16px;">
              <i class="bi bi-save"></i> Guardar configuración de Google
            </button>
          </form>
        <?php endif; ?>

      </div>
    </div>

  </div><!-- /panel-google -->

  <!-- ══════════════════ MICROSOFT ══════════════════ -->
  <div id="panel-microsoft" style="display:none;">

    <?php if ($platformMsOk): ?>
      <div class="ih-alert ih-alert--success">
        <i class="bi bi-shield-check-fill"></i>
        <div><strong>Credenciales Microsoft de plataforma activas.</strong> Solo configurá el Drive ID si usás SharePoint.</div>
      </div>
    <?php endif; ?>

    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><i class="bi bi-microsoft" style="color:#0078D4"></i> Microsoft OneDrive</h2>
          <div class="ih-card__subtitle">
            <?php if (!empty($msCfg['client_id']) || $platformMsOk): ?>
              <span class="ih-badge ih-badge--success ih-badge--dot">Configurado</span>
            <?php else: ?>
              <span class="ih-badge">Sin configurar</span>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($msCfg)): ?>
          <form method="POST" action="/backend/cloud_config_crud.php" style="display:inline"
                onsubmit="return confirm('¿Eliminar configuración de Microsoft?')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="clear">
            <input type="hidden" name="provider" value="microsoft">
            <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm" style="color:var(--ih-danger);">
              <i class="bi bi-trash"></i> Limpiar config
            </button>
          </form>
        <?php endif; ?>
      </div>

      <div class="ih-card__body">

        <?php if (!empty($msCfg)): ?>
          <div style="background:var(--ih-gray-50);border:1px solid var(--ih-gray-200);border-radius:10px;padding:12px 16px;margin-bottom:16px;">
            <div class="ih-text-xs" style="text-transform:uppercase;letter-spacing:.5px;font-weight:700;color:var(--ih-gray-500);margin-bottom:8px;">Configuración actual</div>
            <div class="ih-text-sm ih-mb-1">Tenant ID: <code><?= htmlspecialchars(mask_cfg($msCfg['tenant_id'] ?? '')) ?></code></div>
            <div class="ih-text-sm">Client ID: <code><?= htmlspecialchars(mask_cfg($msCfg['client_id'] ?? '')) ?></code></div>
          </div>
        <?php endif; ?>

        <div class="ih-alert ih-alert--info" style="margin-bottom:16px;font-size:13px;">
          <i class="bi bi-info-circle-fill"></i>
          <div>
            Registrá una app en <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps" target="_blank" rel="noopener">Azure Portal</a>
            → App registrations → New registration. Permisos: <code>Files.ReadWrite</code>, <code>Sites.ReadWrite.All</code>.
          </div>
        </div>

        <form method="POST" action="/backend/cloud_config_crud.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="save_microsoft">
          <input type="hidden" name="provider" value="microsoft">

          <div class="ih-field" style="margin-bottom:12px;">
            <label class="ih-field__label">Tenant ID (Directory ID) <?php if (!$platformMsOk): ?><span style="color:var(--ih-danger)">*</span><?php endif; ?></label>
            <input class="ih-input" type="text" name="ms_tenant_id" <?= !$platformMsOk ? 'required' : '' ?>
                   value="<?= htmlspecialchars($msCfg['tenant_id'] ?? (MS_TENANT_ID !== 'common' ? MS_TENANT_ID : '')) ?>"
                   placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
            <div class="ih-field__hint">Azure AD → Properties → Tenant ID</div>
          </div>

          <div class="ih-field" style="margin-bottom:12px;">
            <label class="ih-field__label">Client ID (App ID) <?php if (!$platformMsOk): ?><span style="color:var(--ih-danger)">*</span><?php endif; ?></label>
            <input class="ih-input" type="text" name="ms_client_id" <?= !$platformMsOk ? 'required' : '' ?>
                   value="<?= htmlspecialchars($msCfg['client_id'] ?? '') ?>"
                   placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
          </div>

          <div class="ih-field" style="margin-bottom:12px;">
            <label class="ih-field__label">Client Secret <?php if (!$platformMsOk): ?><span style="color:var(--ih-danger)">*</span><?php endif; ?></label>
            <input class="ih-input" type="password" name="ms_client_secret" <?= !$platformMsOk ? 'required' : '' ?>
                   placeholder="<?= $platformMsOk ? 'Dejar vacío para usar credencial de plataforma' : 'Secret de Azure' ?>">
          </div>

          <div class="ih-field" style="margin-bottom:12px;">
            <label class="ih-field__label">Redirect URI</label>
            <input class="ih-input" type="url" name="ms_redirect_uri"
                   value="<?= htmlspecialchars($msCfg['redirect_uri'] ?? (MS_REDIRECT_URI ?: ((isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'').'/auth/superadmin_callback.php'))) ?>">
          </div>

          <div class="ih-field" style="margin-bottom:12px;">
            <label class="ih-field__label">Drive ID / Site ID (SharePoint)</label>
            <input class="ih-input" type="text" name="ms_drive_id"
                   value="<?= htmlspecialchars($msCfg['drive_id'] ?? '') ?>"
                   placeholder="Opcional">
          </div>

          <button type="submit" class="ih-btn ih-btn--primary" style="margin-top:4px;">
            <i class="bi bi-save"></i> Guardar configuración Microsoft
          </button>
        </form>

      </div>
    </div>

  </div><!-- /panel-microsoft -->

</main>
</div><!-- /ih-app -->

<script>
function showTab(name) {
  document.getElementById('panel-google').style.display    = name === 'google'    ? '' : 'none';
  document.getElementById('panel-microsoft').style.display = name === 'microsoft' ? '' : 'none';
  document.getElementById('tab-google').className    = 'ih-btn ih-btn--sm ' + (name === 'google' ? 'ih-btn--primary' : 'ih-btn--ghost');
  document.getElementById('tab-microsoft').className = 'ih-btn ih-btn--sm ' + (name === 'microsoft' ? 'ih-btn--primary' : 'ih-btn--ghost');
}
function switchGoogleMode(mode) {
  document.getElementById('google_mode_val').value = mode;
  document.getElementById('google-json-mode').style.display = mode === 'json' ? '' : 'none';
  document.getElementById('google-form-mode').style.display = mode === 'form' ? '' : 'none';
  document.getElementById('gjson-btn').className = 'ih-btn ih-btn--sm ' + (mode === 'json' ? 'ih-btn--primary' : 'ih-btn--ghost');
  document.getElementById('gform-btn').className = 'ih-btn ih-btn--sm ' + (mode === 'form' ? 'ih-btn--primary' : 'ih-btn--ghost');
}
</script>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
