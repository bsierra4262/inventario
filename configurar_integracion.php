<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/csrf.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/core/security/permissions.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: login.php'); exit(); }

$usersTable = tn('users');
$s = $conn->prepare("SELECT role FROM `{$usersTable}` WHERE id = ?");
$s->bind_param('i', $user_id);
$s->execute();
$me = $s->get_result()->fetch_assoc();
$s->close();
require_permission('integration.edit', $me);

$configTable = tn('cloud_config');
$googleCfg = [];
$msCfg     = [];

try {
    $res = $conn->query("SELECT provider, config_key, config_value FROM `{$configTable}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($row['provider'] === 'google') $googleCfg[$row['config_key']] = $row['config_value'];
            else $msCfg[$row['config_key']] = $row['config_value'];
        }
    }
} catch (Throwable $e) {}

$csrf = generateCsrfToken('cloud_config');
$feedbackMsg  = htmlspecialchars($_GET['msg']  ?? '');
$feedbackType = ($_GET['type'] ?? 'success') === 'error' ? 'danger' : 'success';

function mask(string $val): string {
    if (strlen($val) <= 8) return str_repeat('*', strlen($val));
    return substr($val, 0, 4) . str_repeat('*', strlen($val) - 8) . substr($val, -4);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#663399">
<title>Integración Cloud · Asset Vault · INTELLIHELP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/css/intellihelp.css" rel="stylesheet">
<style>
  /* Topbar tenant compartido */
  header.ih-topbar-tenant { display: flex; align-items: center; padding: 0 16px; height: 64px; background: #fff; border-bottom: 1px solid #E4E4E7; position: sticky; top: 0; z-index: 900; box-shadow: 0 1px 3px rgba(17,20,24,.04); }
  header.ih-topbar-tenant .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
  header.ih-topbar-tenant .brand__logo { width: 36px; height: 36px; background: #663399; color: #fff; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; position: relative; }
  header.ih-topbar-tenant .brand__logo::after { content: ''; position: absolute; right: 4px; bottom: 4px; width: 7px; height: 7px; background: #9CC930; border-radius: 50%; }
  header.ih-topbar-tenant .brand__text strong { font-size: 15px; color: #18181B; font-weight: 700; letter-spacing: -.3px; display: block; line-height: 1.1; }
  header.ih-topbar-tenant .brand__text span { font-size: 11px; color: #71717A; }
  header.ih-topbar-tenant .topbar-spacer { flex: 1; }
  header.ih-topbar-tenant a.back { color: #663399; font-weight: 600; font-size: 13px; text-decoration: none; padding: 8px 14px; border-radius: 10px; border: 1px solid #D0BFEA; background: #fff; transition: all .15s; }
  header.ih-topbar-tenant a.back:hover { background: #F4F1F9; }

  .provider-tabs { display: flex; gap: 6px; margin-bottom: 18px; padding: 6px; background: #fff; border-radius: 14px; border: 1px solid #E4E4E7; width: fit-content; }
  .provider-tab { padding: 9px 18px; border-radius: 10px; border: 0; background: transparent; color: #71717A; font-weight: 600; font-size: 14px; cursor: pointer; transition: all .15s; font-family: 'Saira', sans-serif; display: inline-flex; align-items: center; gap: 8px; }
  .provider-tab:hover { background: #F4F1F9; color: #663399; }
  .provider-tab.is-active { background: #663399; color: #fff; }
  .provider-panel { display: none; }
  .provider-panel.is-active { display: block; animation: ih-fade-in 250ms ease; }

  .cfg-current { background: #F4F1F9; border: 1px solid #D0BFEA; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px; }
  .cfg-current code { background: #fff; border: 1px solid #D0BFEA; padding: 2px 8px; border-radius: 6px; font-size: 12px; color: #4E247A; }

  .json-tabs { display: flex; gap: 6px; margin-bottom: 14px; }
  .json-tab { padding: 7px 14px; border-radius: 8px; border: 1px solid #E4E4E7; background: #fff; color: #71717A; font-weight: 600; font-size: 12px; cursor: pointer; font-family: 'Saira', sans-serif; transition: all .15s; }
  .json-tab.is-active { background: #663399; color: #fff; border-color: #663399; }
</style>
</head>
<body>

<header class="ih-topbar-tenant">
  <a class="brand" href="index.php">
    <div class="brand__logo">i</div>
    <div class="brand__text">
      <strong>Asset Vault</strong>
      <span>INTELLIHELP · Inventario</span>
    </div>
  </a>
  <div class="topbar-spacer"></div>
  <a class="back" href="index.php"><i class="bi bi-arrow-left"></i> Volver al inventario</a>
</header>

<main style="max-width:980px;margin:0 auto;padding:24px 16px 60px;">

  <div class="ih-page-header">
    <div>
      <h1 class="ih-page-header__title"><i class="bi bi-cloud-arrow-up-fill" style="color:#663399"></i> Integración Cloud Storage</h1>
      <div class="ih-page-header__subtitle">Conectá Google Drive o Microsoft OneDrive para guardar los archivos de tus activos</div>
    </div>
  </div>

  <?php if ($feedbackMsg): ?>
    <div class="ih-alert ih-alert--<?= $feedbackType === 'danger' ? 'danger' : 'success' ?>">
      <i class="bi bi-<?= $feedbackType === 'danger' ? 'exclamation-triangle' : 'check-circle' ?>-fill"></i>
      <div><?= $feedbackMsg ?></div>
      <button class="ih-alert__close" onclick="this.parentElement.style.display='none'"><i class="bi bi-x"></i></button>
    </div>
  <?php endif; ?>

  <div class="provider-tabs">
    <button class="provider-tab is-active" onclick="showProvider('google', this)">
      <i class="bi bi-google"></i> Google Drive
    </button>
    <button class="provider-tab" onclick="showProvider('microsoft', this)">
      <i class="bi bi-microsoft"></i> Microsoft OneDrive
    </button>
  </div>

  <!-- ============================ GOOGLE ============================ -->
  <div id="prov-google" class="provider-panel is-active">
    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title">
            <i class="bi bi-google" style="color:#EA4335"></i> Google Drive / Workspace
          </h2>
          <div class="ih-card__subtitle">
            <?php if (!empty($googleCfg['client_id'])): ?>
              <span class="ih-badge ih-badge--success ih-badge--dot">Configurado</span>
            <?php else: ?>
              <span class="ih-badge">Sin configurar</span>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($googleCfg)): ?>
          <form method="POST" action="backend/cloud_config_crud.php" style="display:inline" onsubmit="return confirm('¿Eliminar configuración de Google?')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="clear">
            <input type="hidden" name="provider" value="google">
            <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm" style="color:#DC2626">
              <i class="bi bi-trash"></i> Limpiar
            </button>
          </form>
        <?php endif; ?>
      </div>

      <div class="ih-card__body">

        <?php if (!empty($googleCfg)): ?>
          <div class="cfg-current">
            <div class="ih-text-xs ih-fw-700 ih-text-muted" style="text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Configuración actual</div>
            <div class="ih-text-sm">
              Client ID: <code><?= htmlspecialchars(mask($googleCfg['client_id'] ?? '')) ?></code>
            </div>
            <?php if (!empty($googleCfg['redirect_uri'])): ?>
              <div class="ih-text-sm ih-mt-2">Redirect URI: <code><?= htmlspecialchars($googleCfg['redirect_uri']) ?></code></div>
            <?php endif; ?>
            <?php if (!empty($googleCfg['root_folder_id'])): ?>
              <div class="ih-text-sm ih-mt-2">Carpeta raíz Drive: <code><?= htmlspecialchars($googleCfg['root_folder_id']) ?></code></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="ih-alert ih-alert--info" style="font-size:13px;">
          <i class="bi bi-info-circle-fill"></i>
          <div>
            Descargá el JSON desde
            <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>
            → Credenciales → OAuth 2.0 Client → Descargar JSON. Asegurate de habilitar la <strong>Drive API</strong>.
          </div>
        </div>

        <div class="json-tabs">
          <button class="json-tab is-active" type="button" onclick="switchGoogleMode('json', this)">
            <i class="bi bi-file-earmark-code"></i> Pegar / Subir JSON
          </button>
          <button class="json-tab" type="button" onclick="switchGoogleMode('form', this)">
            <i class="bi bi-pencil"></i> Ingresar manualmente
          </button>
        </div>

        <form method="POST" action="backend/cloud_config_crud.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="save_google">
          <input type="hidden" name="provider" value="google">
          <input type="hidden" name="google_mode" id="google_mode_input" value="json">

          <!-- Modo JSON -->
          <div id="google-json-mode">
            <div class="ih-field">
              <label class="ih-field__label">Subir archivo credentials.json</label>
              <input type="file" name="credentials_file" class="ih-input" accept=".json">
            </div>
            <div class="ih-field">
              <label class="ih-field__label">O pegar el contenido del JSON</label>
              <textarea name="credentials_json" class="ih-textarea" rows="6" style="font-family:monospace;font-size:12px" placeholder='{"web":{"client_id":"...","client_secret":"...","redirect_uris":[...]}}'></textarea>
            </div>
          </div>

          <!-- Modo Manual -->
          <div id="google-form-mode" style="display:none">
            <div class="ih-grid">
              <div class="ih-col-6">
                <div class="ih-field">
                  <label class="ih-field__label">Client ID <span class="req">*</span></label>
                  <input class="ih-input" type="text" name="google_client_id"
                         value="<?= htmlspecialchars($googleCfg['client_id'] ?? '') ?>"
                         placeholder="xxxxx.apps.googleusercontent.com">
                </div>
              </div>
              <div class="ih-col-6">
                <div class="ih-field">
                  <label class="ih-field__label">Client Secret <span class="req">*</span></label>
                  <input class="ih-input" type="text" name="google_client_secret" placeholder="GOCSPX-...">
                </div>
              </div>
              <div class="ih-col-8">
                <div class="ih-field">
                  <label class="ih-field__label">Redirect URI</label>
                  <input class="ih-input" type="url" name="google_redirect_uri"
                         value="<?= htmlspecialchars($googleCfg['redirect_uri'] ?? ((isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').'/login.php')) ?>">
                </div>
              </div>
              <div class="ih-col-4">
                <div class="ih-field">
                  <label class="ih-field__label">ID Carpeta raíz Drive</label>
                  <input class="ih-input" type="text" name="google_root_folder"
                         value="<?= htmlspecialchars($googleCfg['root_folder_id'] ?? '') ?>"
                         placeholder="1BxiMVs... (opcional)">
                </div>
              </div>
            </div>
          </div>

          <button type="submit" class="ih-btn ih-btn--accent ih-mt-4">
            <i class="bi bi-save"></i> Guardar configuración de Google
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ============================ MICROSOFT ============================ -->
  <div id="prov-microsoft" class="provider-panel">
    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title">
            <i class="bi bi-microsoft" style="color:#0078D4"></i> Microsoft OneDrive / SharePoint
          </h2>
          <div class="ih-card__subtitle">
            <?php if (!empty($msCfg['client_id'])): ?>
              <span class="ih-badge ih-badge--success ih-badge--dot">Configurado</span>
            <?php else: ?>
              <span class="ih-badge">Sin configurar</span>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($msCfg)): ?>
          <form method="POST" action="backend/cloud_config_crud.php" style="display:inline" onsubmit="return confirm('¿Eliminar configuración de Microsoft?')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="clear">
            <input type="hidden" name="provider" value="microsoft">
            <button type="submit" class="ih-btn ih-btn--ghost ih-btn--sm" style="color:#DC2626">
              <i class="bi bi-trash"></i> Limpiar
            </button>
          </form>
        <?php endif; ?>
      </div>

      <div class="ih-card__body">
        <?php if (!empty($msCfg)): ?>
          <div class="cfg-current">
            <div class="ih-text-xs ih-fw-700 ih-text-muted" style="text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Configuración actual</div>
            <div class="ih-text-sm">Tenant ID: <code><?= htmlspecialchars(mask($msCfg['tenant_id'] ?? '')) ?></code></div>
            <div class="ih-text-sm ih-mt-2">Client ID: <code><?= htmlspecialchars(mask($msCfg['client_id'] ?? '')) ?></code></div>
          </div>
        <?php endif; ?>

        <div class="ih-alert ih-alert--info" style="font-size:13px;">
          <i class="bi bi-info-circle-fill"></i>
          <div>
            Registrá una app en
            <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps" target="_blank" rel="noopener">Azure Portal</a>
            → App registrations → New registration. Permisos necesarios: <code>Files.ReadWrite</code>, <code>Sites.ReadWrite.All</code>.
          </div>
        </div>

        <form method="POST" action="backend/cloud_config_crud.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="save_microsoft">
          <input type="hidden" name="provider" value="microsoft">

          <div class="ih-grid">
            <div class="ih-col-4">
              <div class="ih-field">
                <label class="ih-field__label">Tenant ID (Directory ID) <span class="req">*</span></label>
                <input class="ih-input" type="text" name="ms_tenant_id" required
                       value="<?= htmlspecialchars($msCfg['tenant_id'] ?? '') ?>"
                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                <div class="ih-field__hint">Azure AD → Properties → Tenant ID</div>
              </div>
            </div>
            <div class="ih-col-4">
              <div class="ih-field">
                <label class="ih-field__label">Client ID (App ID) <span class="req">*</span></label>
                <input class="ih-input" type="text" name="ms_client_id" required
                       value="<?= htmlspecialchars($msCfg['client_id'] ?? '') ?>"
                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
              </div>
            </div>
            <div class="ih-col-4">
              <div class="ih-field">
                <label class="ih-field__label">Client Secret <span class="req">*</span></label>
                <input class="ih-input" type="password" name="ms_client_secret" required placeholder="Secret de Azure">
                <div class="ih-field__hint">Certificates &amp; secrets → New</div>
              </div>
            </div>
            <div class="ih-col-8">
              <div class="ih-field">
                <label class="ih-field__label">Redirect URI</label>
                <input class="ih-input" type="url" name="ms_redirect_uri"
                       value="<?= htmlspecialchars($msCfg['redirect_uri'] ?? ((isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').'/login.php')) ?>">
              </div>
            </div>
            <div class="ih-col-4">
              <div class="ih-field">
                <label class="ih-field__label">Drive ID / Site ID (SharePoint)</label>
                <input class="ih-input" type="text" name="ms_drive_id"
                       value="<?= htmlspecialchars($msCfg['drive_id'] ?? '') ?>"
                       placeholder="Opcional">
              </div>
            </div>
          </div>

          <button type="submit" class="ih-btn ih-btn--primary ih-mt-4">
            <i class="bi bi-save"></i> Guardar configuración de Microsoft
          </button>
        </form>
      </div>
    </div>
  </div>

</main>

<script>
function showProvider(name, btn) {
  document.querySelectorAll('.provider-panel').forEach(p => p.classList.remove('is-active'));
  document.querySelectorAll('.provider-tab').forEach(b => b.classList.remove('is-active'));
  document.getElementById('prov-' + name).classList.add('is-active');
  btn.classList.add('is-active');
}
function switchGoogleMode(mode, btn) {
  document.getElementById('google_mode_input').value = mode;
  document.getElementById('google-json-mode').style.display = mode === 'json' ? '' : 'none';
  document.getElementById('google-form-mode').style.display = mode === 'form' ? '' : 'none';
  document.querySelectorAll('.json-tab').forEach(b => b.classList.remove('is-active'));
  btn.classList.add('is-active');
}
</script>
</body>
</html>
