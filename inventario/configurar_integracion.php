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
require_once __DIR__ . '/../includes/cloud_icons.php';
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
require_once __DIR__ . '/../core/db/empresa_cache.php';
$tenant_name = empresa_nombre();

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
  <?php /* El estado del acceso con contraseña, con el botón que lo cambia al
           lado. Es un aviso con una acción, no una tarjeta: así se lee como lo
           que es —algo que hay que saber antes de seguir— y no como una
           sección más de la pantalla. */ ?>
  <div class="ih-alert <?= $localLoginDisabled ? 'ih-alert--info' : 'ih-alert--warning' ?> ih-alert--accion">
    <i class="bi bi-shield-lock-fill"></i>
    <div class="ih-alert__cuerpo">
      <strong>Entrar con usuario y contraseña:
        <?= $localLoginDisabled ? 'no se puede' : 'se puede' ?></strong><br>
      <?php if ($localLoginDisabled): ?>
        Todos entran con Google o con Microsoft. En la pantalla de acceso no aparece la opción de
        usuario y contraseña.
      <?php else: ?>
        Además de Google y Microsoft, se puede entrar con usuario y contraseña. Queda como salida
        de emergencia si el acceso con Google deja de funcionar, y en la pantalla de acceso aparece
        como <em>··· otra forma de entrar</em>.
      <?php endif; ?>
    </div>
    <form class="ih-alert__accion" method="POST" action="/inventario/configurar_integracion.php">
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
  <?php endif; ?>

  <!-- Tabs de proveedor -->
  <?php /* Qué nube se está configurando. Son pastillas y no pestañas: no
           navegan a otra pantalla, muestran y esconden los paneles de abajo. */ ?>
  <div class="ih-pills" role="group" aria-label="Nube que se está configurando">
    <button type="button" id="tab-google" class="ih-pill is-active"
            aria-pressed="true" onclick="showTab('google')">
      <?= marca_svg('drive', 17) ?> Google Drive
    </button>
    <button type="button" id="tab-microsoft" class="ih-pill"
            aria-pressed="false" onclick="showTab('microsoft')">
      <?= marca_svg('onedrive', 17) ?> Microsoft OneDrive
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

    <div class="ih-card ih-mb-5">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><?= marca_svg('drive', 20) ?> Google Drive</h2>
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
          <form method="POST" action="/backend/cloud_config_crud.php"
                onsubmit="return confirm('¿Eliminar toda la configuración de Google?')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="clear">
            <input type="hidden" name="provider" value="google">
            <button type="submit" class="ih-btn ih-btn--danger ih-btn--sm">
              <i class="bi bi-trash"></i> Limpiar config
            </button>
          </form>
        <?php endif; ?>
      </div>

      <div class="ih-card__body">

        <?php if (!empty($googleCfg)): ?>
          <div class="ih-resumen">
            <h3 class="ih-seccion__titulo">Lo que ya está configurado para esta empresa</h3>
            <?php if (!empty($googleCfg['client_id'])): ?>
              <div class="ih-text-sm ih-mb-1">Client ID: <code><?= htmlspecialchars(mask_cfg($googleCfg['client_id'])) ?></code></div>
            <?php endif; ?>
            <?php if (!empty($googleCfg['root_folder_id'])): ?>
              <div class="ih-text-sm">
                Carpeta raíz Drive: <code><?= htmlspecialchars($googleCfg['root_folder_id']) ?></code>
                <a href="https://drive.google.com/drive/folders/<?= urlencode($googleCfg['root_folder_id']) ?>"
                   target="_blank" rel="noopener" class="ih-text-xs">
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

            <div class="ih-alert ih-alert--info">
              <i class="bi bi-folder2-open"></i>
              <div>
                Creá una carpeta en Google Drive para guardar los archivos de esta empresa,
                copiá su ID desde la URL (<code>drive.google.com/drive/folders/<strong>ID_aquí</strong></code>)
                y pegalo abajo.
              </div>
            </div>

            <div class="ih-field">
              <label class="ih-field__label" for="g-carpeta">ID de la carpeta raíz en Google Drive <span class="ih-field__req" aria-hidden="true">*</span></label>
              <input class="ih-input" type="text" id="g-carpeta" name="google_root_folder" required
                     value="<?= htmlspecialchars($googleCfg['root_folder_id'] ?? '') ?>"
                     placeholder="Ej: 1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms">
              <div class="ih-field__hint">El ID de la carpeta de Drive donde se guardarán los archivos adjuntos.</div>
            </div>

            <button type="submit" class="ih-btn ih-btn--accent">
              <i class="bi bi-save"></i> Guardar carpeta Drive
            </button>
          </form>

        <?php else: ?>
          <!-- Sin credenciales de plataforma: permitir ingresar credenciales propias -->
          <div class="ih-pills" role="group" aria-label="De dónde salen las credenciales">
            <button type="button" id="gjson-btn" class="ih-pill is-active"
                    aria-pressed="true" onclick="switchGoogleMode('json')">
              <i class="bi bi-file-earmark-code"></i> Pegar el JSON
            </button>
            <button type="button" id="gform-btn" class="ih-pill"
                    aria-pressed="false" onclick="switchGoogleMode('form')">
              <i class="bi bi-pencil"></i> Campo por campo
            </button>
          </div>

          <form method="POST" action="/backend/cloud_config_crud.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_google">
            <input type="hidden" name="provider" value="google">
            <input type="hidden" name="google_mode" id="google_mode_val" value="json">

            <!-- Modo JSON -->
            <div id="google-json-mode">
              <div class="ih-alert ih-alert--info">
                <i class="bi bi-info-circle-fill"></i>
                <div>Descargá el JSON desde <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a> → Credenciales → OAuth 2.0 Client → Descargar JSON.</div>
              </div>
              <div class="ih-field">
                <label class="ih-field__label">Subir credentials.json</label>
                <input type="file" name="credentials_file" class="ih-input" accept=".json">
              </div>
              <div class="ih-field">
                <label class="ih-field__label">O pegar el contenido JSON</label>
                <textarea name="credentials_json" class="ih-input" rows="5"
                          class="ih-input ih-input--codigo"
                          placeholder='{"web":{"client_id":"...","client_secret":"...","redirect_uris":[...]}}'></textarea>
              </div>
            </div>

            <!-- Modo Manual -->
            <div id="google-form-mode" hidden>
              <div class="ih-field">
                <label class="ih-field__label">Client ID <span class="ih-field__req" aria-hidden="true">*</span></label>
                <input class="ih-input" type="text" name="google_client_id"
                       value="<?= htmlspecialchars($googleCfg['client_id'] ?? '') ?>"
                       placeholder="xxxxx.apps.googleusercontent.com">
              </div>
              <div class="ih-field">
                <label class="ih-field__label">Client Secret <span class="ih-field__req" aria-hidden="true">*</span></label>
                <input class="ih-input" type="text" name="google_client_secret" placeholder="GOCSPX-...">
              </div>
              <div class="ih-field">
                <label class="ih-field__label">Redirect URI</label>
                <input class="ih-input" type="url" name="google_redirect_uri"
                       value="<?= htmlspecialchars($googleCfg['redirect_uri'] ?? ((isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'').'/auth/superadmin_callback.php')) ?>" placeholder="Ej.: https://inventario.tuempresa.com/auth/google_callback.php">
              </div>
            </div>

            <!-- root_folder_id siempre visible -->
            <div class="ih-field">
              <label class="ih-field__label" for="g-carpeta-propia">ID de la carpeta raíz en Drive <span class="ih-field__opcional">(se puede dejar vacío)</span></label>
              <input class="ih-input" type="text" id="g-carpeta-propia" name="google_root_folder"
                     value="<?= htmlspecialchars($googleCfg['root_folder_id'] ?? '') ?>"
                     placeholder="1BxiMVs...">
              <div class="ih-field__hint">La carpeta de Drive donde se guardarán los archivos adjuntos de esta empresa.</div>
            </div>

            <button type="submit" class="ih-btn ih-btn--accent">
              <i class="bi bi-save"></i> Guardar configuración de Google
            </button>
          </form>
        <?php endif; ?>

      </div>
    </div>

  </div><!-- /panel-google -->

  <!-- ══════════════════ MICROSOFT ══════════════════ -->
  <div id="panel-microsoft" hidden>

    <?php if ($platformMsOk): ?>
      <div class="ih-alert ih-alert--success">
        <i class="bi bi-shield-check-fill"></i>
        <div><strong>Credenciales Microsoft de plataforma activas.</strong> Solo configurá el Drive ID si usás SharePoint.</div>
      </div>
    <?php endif; ?>

    <div class="ih-card">
      <div class="ih-card__header">
        <div>
          <h2 class="ih-card__title"><?= marca_svg('onedrive', 20) ?> Microsoft OneDrive</h2>
          <div class="ih-card__subtitle">
            <?php if (!empty($msCfg['client_id']) || $platformMsOk): ?>
              <span class="ih-badge ih-badge--success ih-badge--dot">Configurado</span>
            <?php else: ?>
              <span class="ih-badge">Sin configurar</span>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($msCfg)): ?>
          <form method="POST" action="/backend/cloud_config_crud.php"
                onsubmit="return confirm('¿Eliminar configuración de Microsoft?')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="clear">
            <input type="hidden" name="provider" value="microsoft">
            <button type="submit" class="ih-btn ih-btn--danger ih-btn--sm">
              <i class="bi bi-trash"></i> Limpiar config
            </button>
          </form>
        <?php endif; ?>
      </div>

      <div class="ih-card__body">

        <?php if (!empty($msCfg)): ?>
          <div class="ih-resumen">
            <h3 class="ih-seccion__titulo">Lo que ya está configurado</h3>
            <div class="ih-text-sm ih-mb-1">Tenant ID: <code><?= htmlspecialchars(mask_cfg($msCfg['tenant_id'] ?? '')) ?></code></div>
            <div class="ih-text-sm">Client ID: <code><?= htmlspecialchars(mask_cfg($msCfg['client_id'] ?? '')) ?></code></div>
          </div>
        <?php endif; ?>

        <div class="ih-alert ih-alert--info">
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

          <div class="ih-field">
            <label class="ih-field__label">Tenant ID (Directory ID) <?php if (!$platformMsOk): ?><span class="ih-field__req" aria-hidden="true">*</span><?php endif; ?></label>
            <input class="ih-input" type="text" name="ms_tenant_id" <?= !$platformMsOk ? 'required' : '' ?>
                   value="<?= htmlspecialchars($msCfg['tenant_id'] ?? (MS_TENANT_ID !== 'common' ? MS_TENANT_ID : '')) ?>"
                   placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
            <div class="ih-field__hint">Azure AD → Properties → Tenant ID</div>
          </div>

          <div class="ih-field">
            <label class="ih-field__label">Client ID (App ID) <?php if (!$platformMsOk): ?><span class="ih-field__req" aria-hidden="true">*</span><?php endif; ?></label>
            <input class="ih-input" type="text" name="ms_client_id" <?= !$platformMsOk ? 'required' : '' ?>
                   value="<?= htmlspecialchars($msCfg['client_id'] ?? '') ?>"
                   placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
          </div>

          <div class="ih-field">
            <label class="ih-field__label">Client Secret <?php if (!$platformMsOk): ?><span class="ih-field__req" aria-hidden="true">*</span><?php endif; ?></label>
            <input class="ih-input" type="password" name="ms_client_secret" <?= !$platformMsOk ? 'required' : '' ?>
                   placeholder="<?= $platformMsOk ? 'Dejar vacío para usar credencial de plataforma' : 'Secret de Azure' ?>">
          </div>

          <div class="ih-field">
            <label class="ih-field__label">Redirect URI</label>
            <input class="ih-input" type="url" name="ms_redirect_uri"
                   value="<?= htmlspecialchars($msCfg['redirect_uri'] ?? (MS_REDIRECT_URI ?: ((isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'').'/auth/superadmin_callback.php'))) ?>" placeholder="Ej.: https://inventario.tuempresa.com/auth/ms_callback.php">
          </div>

          <div class="ih-field">
            <label class="ih-field__label">Drive ID / Site ID (SharePoint)</label>
            <input class="ih-input" type="text" name="ms_drive_id"
                   value="<?= htmlspecialchars($msCfg['drive_id'] ?? '') ?>"
                   placeholder="Opcional">
          </div>

          <button type="submit" class="ih-btn ih-btn--primary">
            <i class="bi bi-save"></i> Guardar configuración Microsoft
          </button>
        </form>

      </div>
    </div>

  </div><!-- /panel-microsoft -->

</main>
</div><!-- /ih-app -->

<script>
/* Las pastillas muestran y esconden paneles.

   Dos cosas cambiaron respecto de cómo estaba:
   • Los paneles se esconden con el atributo `hidden` y no con `style.display`,
     así el estado vive en el HTML y no en un estilo escrito a mano.
   • La pastilla activa se marca agregando y quitando una clase, en lugar de
     reescribirle el `className` entero. Lo de antes funcionaba, pero borraba
     de paso cualquier otra clase que el botón tuviera —y ahora tiene una. */
(function () {
  function marcar(boton, activa) {
    if (!boton) { return; }
    boton.classList.toggle('is-active', activa);
    boton.setAttribute('aria-pressed', activa ? 'true' : 'false');
  }
  function mostrar(el, visible) { if (el) { el.hidden = !visible; } }

  window.showTab = function (nombre) {
    mostrar(document.getElementById('panel-google'),    nombre === 'google');
    mostrar(document.getElementById('panel-microsoft'), nombre === 'microsoft');
    marcar(document.getElementById('tab-google'),    nombre === 'google');
    marcar(document.getElementById('tab-microsoft'), nombre === 'microsoft');
  };

  window.switchGoogleMode = function (modo) {
    var campo = document.getElementById('google_mode_val');
    if (campo) { campo.value = modo; }
    mostrar(document.getElementById('google-json-mode'), modo === 'json');
    mostrar(document.getElementById('google-form-mode'), modo === 'form');
    marcar(document.getElementById('gjson-btn'), modo === 'json');
    marcar(document.getElementById('gform-btn'), modo === 'form');
  };
})();
</script>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
