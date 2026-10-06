<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
$_errLog = __DIR__ . '/../logs/php_errors.log';
@ini_set('error_log', $_errLog);
error_reporting(E_ALL);

require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start(); // sesión con cookie HttpOnly + SameSite + strict mode

// ── Logger y vendor ───────────────────────────────────────────────────────────
$loggerPath = __DIR__ . '/../logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'app') {}
    }
}

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    try { require_once $vendorAutoload; }
    catch (Throwable $ve) { error_log('login.php: vendor broken: ' . $ve->getMessage()); }
} else {
    error_log('login.php: vendor/autoload.php no existe');
}

// ── 1) Resolver empresa ───────────────────────────────────────────────────────
require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/db/master_connection.php';

$companies = [];
$masterDbError = '';
try {
    $master = new MasterDB();
    $stmt   = $master->pquery('SELECT id, nombre, subdomain FROM companies WHERE active = 1 ORDER BY nombre ASC');
    $res    = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $companies[] = $row; }
} catch (Throwable $e) {
    log_event('login.php', 'ERROR', 'Master DB: ' . $e->getMessage(), 'auth');
    $masterDbError = $e->getMessage();
}

if ($masterDbError !== '') {
    header('Location: /install.php');
    exit();
}

if (empty($companies)) {
    $page_title = 'Servicio no disponible';
    require __DIR__ . '/../includes/layout/head.php';
    ?>
    <div class="ih-auth">
      <div class="ih-auth__card ih-anim-fade">
        <div class="ih-auth__header">
          <div class="ih-auth__brand">
            <div class="ih-auth__logo"><?= platform_logo_html() ?></div>
            <div class="ih-auth__brand-text"><strong>INTELLIHELP</strong><span>Asset Vault</span></div>
          </div>
          <h2 class="ih-auth__title">Servicio no disponible</h2>
          <p class="ih-auth__subtitle">No hay empresas activas para iniciar sesión</p>
        </div>
        <div class="ih-auth__body">
          <div class="ih-alert ih-alert--info">
            <i class="bi bi-info-circle-fill"></i>
            <div>El servicio se encuentra temporalmente no disponible. Por favor contactá al administrador del sistema.</div>
          </div>
        </div>
        <div class="ih-auth__footer">© <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault</div>
      </div>
    </div>
    <?php
    require __DIR__ . '/../includes/layout/foot.php';
    exit;
}

// Pre-seleccionar empresa desde ?company=id o subdomain
if (!empty($_GET['company']) && empty($_SESSION['company_id'])) {
    $slug = preg_replace('/[^a-z0-9\-_]/i', '', (string)$_GET['company']);
    foreach ($companies as $c) {
        if ((string)$c['id'] === $slug || strcasecmp((string)($c['subdomain'] ?? ''), $slug) === 0) {
            $_SESSION['company_id'] = (int)$c['id'];
            break;
        }
    }
}

// El company_id del POST se valida contra la lista de empresas ACTIVAS, igual que
// el del querystring. Antes se aceptaba cualquier número, así que se podía forzar
// la sesión sobre una empresa desactivada.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['company_id']) && ctype_digit((string)$_POST['company_id'])) {
    $postCid = (int)$_POST['company_id'];
    foreach ($companies as $c) {
        if ((int)$c['id'] === $postCid) { $_SESSION['company_id'] = $postCid; break; }
    }
}

$must_choose_company = false;
if (empty($_SESSION['company_id'])) {
    if (count($companies) === 1) {
        $_SESSION['company_id'] = (int)$companies[0]['id'];
    } else {
        $must_choose_company = true;
    }
}

// ── 2) Conexión tenant ────────────────────────────────────────────────────────
$conn = null;
$tenantDbError = '';
if (!empty($_SESSION['company_id'])) {
    try {
        require_once __DIR__ . '/../backend/db.php';
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        if (isset($conn) && $conn instanceof mysqli) { @$conn->set_charset('utf8mb4'); }
    } catch (Throwable $e) {
        $tenantDbError = $e->getMessage();
        log_event('login.php', 'ERROR', 'Tenant DB: ' . $tenantDbError, 'auth');
        $conn = null;
    }
}

if (!empty($_SESSION['company_id']) && !($conn instanceof mysqli)) {
    $page_title = 'Empresa no disponible';
    require __DIR__ . '/../includes/layout/head.php';
    ?>
    <div class="ih-auth">
      <div class="ih-auth__card ih-anim-fade">
        <div class="ih-auth__header">
          <div class="ih-auth__brand">
            <div class="ih-auth__logo"><?= function_exists('platform_logo_html') ? platform_logo_html() : 'i' ?></div>
            <div class="ih-auth__brand-text"><strong>INTELLIHELP</strong><span>Asset Vault</span></div>
          </div>
          <h2 class="ih-auth__title">Servicio no disponible</h2>
          <p class="ih-auth__subtitle">No se puede acceder al inventario en este momento</p>
        </div>
        <div class="ih-auth__body">
          <div class="ih-alert ih-alert--warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>
              El servicio se encuentra temporalmente no disponible. Por favor contactá al administrador del sistema.
            </div>
          </div>
        </div>
        <div class="ih-auth__footer">© <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault</div>
      </div>
    </div>
    <?php
    require __DIR__ . '/../includes/layout/foot.php';
    exit;
}

// ── 3) Leer config OAuth del tenant ──────────────────────────────────────────
// Callback unificado: UNA SOLA URI registrada en Google Console y Azure
$callbackUri = GOOGLE_REDIRECT_URI ?: ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/superadmin_callback.php');

// --- Google: empezar con credenciales de plataforma, override por tenant ---
$googleClientId     = GOOGLE_CLIENT_ID;
$googleClientSecret = GOOGLE_CLIENT_SECRET;

// --- Microsoft: empezar con credenciales de plataforma, override por tenant ---
$msClientId         = MS_CLIENT_ID;
$msClientSecret     = MS_CLIENT_SECRET;
$msTenantId         = MS_TENANT_ID ?: 'common';
$msCallbackUri      = MS_REDIRECT_URI ?: $callbackUri;

if ($conn instanceof mysqli) {
    try {
        $cfgTable = tn('cloud_config');
        $cfgRes   = $conn->query("SELECT provider, config_key, config_value FROM `{$cfgTable}` WHERE provider IN ('google','microsoft')");
        if ($cfgRes) {
            while ($cfgRow = $cfgRes->fetch_assoc()) {
                match ([$cfgRow['provider'], $cfgRow['config_key']]) {
                    ['google',    'client_id']     => $googleClientId     = (string)$cfgRow['config_value'],
                    ['google',    'client_secret'] => $googleClientSecret = (string)$cfgRow['config_value'],
                    ['google',    'redirect_uri']  => $callbackUri        = (string)$cfgRow['config_value'],
                    ['microsoft', 'client_id']     => $msClientId         = (string)$cfgRow['config_value'],
                    ['microsoft', 'client_secret'] => $msClientSecret     = (string)$cfgRow['config_value'],
                    ['microsoft', 'tenant_id']     => $msTenantId         = (string)$cfgRow['config_value'],
                    ['microsoft', 'redirect_uri']  => $msCallbackUri      = (string)$cfgRow['config_value'],
                    default => null,
                };
            }
        }
    } catch (Throwable $e) {
        log_event('login.php', 'ERROR', 'Lectura cloud_config: ' . $e->getMessage(), 'auth');
    }
}

$googleConfigured = ($googleClientId !== '' && $googleClientSecret !== '');
$msConfigured     = ($msClientId !== '' && $msClientSecret !== '');
$ssoConfigured    = $googleConfigured || $msConfigured;
$companyId        = (int)($_SESSION['company_id'] ?? 0);

// ── Generar URL de autorización Google ───────────────────────────────────────
$googleAuthUrl = null;
if ($googleConfigured && !$must_choose_company) {
    try {
        $gClient = new Google_Client();
        $gClient->setClientId($googleClientId);
        $gClient->setClientSecret($googleClientSecret);
        $gClient->setRedirectUri($callbackUri);
        $gClient->addScope(['email', 'profile', Google_Service_Drive::DRIVE, Google_Service_Drive::DRIVE_FILE]);
        $gClient->setAccessType('offline');
        $gClient->setPrompt('select_account consent');

        // State: tg:{random32}:{company_id}
        $gRand = bin2hex(random_bytes(16));
        $_SESSION['tenant_oauth_state_rand'] = $gRand;
        $gClient->setState('tg:' . $gRand . ':' . $companyId);

        $googleAuthUrl = $gClient->createAuthUrl();
    } catch (Throwable $e) {
        log_event('login.php', 'ERROR', 'Google client init: ' . $e->getMessage(), 'auth');
        $googleConfigured = false;
    }
}

// ── Generar URL de autorización Microsoft ─────────────────────────────────────
$msAuthUrl = null;
if ($msConfigured && !$must_choose_company) {
    try {
        // State: tm:{random32}:{company_id}
        $mRand = bin2hex(random_bytes(16));
        $_SESSION['tenant_ms_oauth_state_rand'] = $mRand;
        $msState = 'tm:' . $mRand . ':' . $companyId;

        $msAuthUrl = 'https://login.microsoftonline.com/' . urlencode($msTenantId) . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id'     => $msClientId,
            'response_type' => 'code',
            'redirect_uri'  => $msCallbackUri,
            'response_mode' => 'query',
            'scope'         => 'openid profile email User.Read',
            'state'         => $msState,
            'prompt'        => 'select_account',
        ]);
    } catch (Throwable $e) {
        log_event('login.php', 'ERROR', 'MS client init: ' . $e->getMessage(), 'auth');
        $msConfigured = false;
    }
}

// Recalcular tras posibles errores
$ssoConfigured = ($googleAuthUrl !== null) || ($msAuthUrl !== null);

// ── 5) LOGIN LOCAL (solo si SSO no está configurado o ?local=1) ───────────────
// El admin del cliente puede DESHABILITAR el login local en configurar_integracion.php
// (solo aplica si tiene SSO configurado — sin SSO permitimos siempre el local para no
// dejarlos sin acceso).
require_once __DIR__ . '/../core/tenants/tenant_settings.php';
$localDisabled = ($conn instanceof mysqli) && $ssoConfigured && tenant_local_login_disabled($conn);

$error_msg  = '';
$show_local = !$ssoConfigured || (!empty($_GET['local']) && !$localDisabled);

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['username'], $_POST['password'])
) {
    if ($localDisabled) {
        $error_msg = 'El acceso con usuario y contraseña está deshabilitado para esta empresa. Ingresá con Google o Microsoft.';
    } elseif ($must_choose_company && empty($_POST['company_id'])) {
        $error_msg = 'Seleccioná una empresa para continuar.';
    } elseif (!($conn instanceof mysqli)) {
        $error_msg = 'La empresa no está disponible en este momento.';
    } else {
        $username = trim((string)$_POST['username']);
        $password = (string)$_POST['password'];

        $user = null;
        try {
            $stmt = $conn->prepare("SELECT id, password, role, email FROM " . tn('users') . " WHERE username = ?");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Throwable $e) {
            $error_msg = 'Error interno al verificar credenciales.';
            log_event('login.php', 'ERROR', 'DB login local: ' . $e->getMessage(), 'auth');
        }

        if ($user && password_verify($password, $user['password'])) {
            // Rotar el ID de sesión al autenticar (anti fijación de sesión).
            // app_session_rotar() conserva company_id, descarta el resto y cierra
            // la sesión antes del redirect, que era el problema real que en su
            // momento se atribuyó a session_regenerate_id().
            require_once __DIR__ . '/../core/security/session_guard.php';
            app_session_rotar(['company_id']);

            $_SESSION['user_id']    = (int)$user['id'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // Empresa contra la que se validó esta credencial. backend/db.php la
            // compara con company_id en cada request: si alguien cambia la
            // empresa sin volver a autenticarse, la sesión se destruye en vez de
            // reinterpretar el user_id contra las tablas de otro tenant.
            $_SESSION['auth_company_id'] = (int)($_SESSION['company_id'] ?? 0);

            log_event($user['email'] ?? "id:{$user['id']}", "LOGIN LOCAL", "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 'auth');

            // Verificar cambio de contraseña obligatorio
            $mustChange = false;
            try {
                $mcStmt = $conn->prepare("SELECT must_change_password FROM " . tn('users') . " WHERE id = ?");
                $mcStmt->bind_param('i', (int)$user['id']);
                $mcStmt->execute();
                $mcRow = $mcStmt->get_result()->fetch_assoc();
                $mcStmt->close();
                $mustChange = !empty($mcRow['must_change_password']);
            } catch (Throwable) {}

            if ($mustChange) {
                $_SESSION['force_password_change'] = true;
                session_write_close();
                header('Location: /auth/change_password.php');
                exit();
            }

            session_write_close();
            header('Location: /inventario/index.php');
            exit();

        } elseif (!$error_msg) {
            $error_msg = 'Credenciales inválidas.';
            log_event("Usuario: $username", "LOGIN FALLIDO", "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 'auth');
        }
    }
}

// Errores reenviados desde el callback OAuth
if (!empty($_GET['err']) && $error_msg === '') {
    $error_msg = (string)$_GET['err'];
}

// ── Nombre de la empresa seleccionada ────────────────────────────────────────
$companyName = '';
foreach ($companies as $c) {
    if ((int)$c['id'] === $companyId) { $companyName = $c['nombre']; break; }
}

$page_title = 'Iniciar sesión' . ($companyName ? " · {$companyName}" : '');
require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-auth">
  <div class="ih-auth__card ih-anim-fade">
    <div class="ih-auth__header">
      <div class="ih-auth__brand">
        <div class="ih-auth__logo"><?= platform_logo_html() ?></div>
        <div class="ih-auth__brand-text">
          <strong>INTELLIHELP</strong>
          <span><?= $companyName ? htmlspecialchars($companyName) : 'Asset Vault' ?></span>
        </div>
      </div>
      <h2 class="ih-auth__title">Bienvenido</h2>
      <p class="ih-auth__subtitle">Accedé a tu inventario corporativo</p>
    </div>

    <div class="ih-auth__body">

      <?php if (!empty($_GET['bye'])): ?>
        <div class="ih-alert ih-alert--success">
          <i class="bi bi-check-circle-fill"></i>
          <div>Tu sesión se cerró correctamente.</div>
        </div>
      <?php endif; ?>

      <?php /* Viene de auth/change_password.php, que antes avisaba con un
               alert() del navegador. */ ?>
      <?php if (!empty($_GET['pass'])): ?>
        <div class="ih-alert ih-alert--success">
          <i class="bi bi-check-circle-fill"></i>
          <div>Tu contraseña se cambió correctamente. Ingresá de nuevo para continuar.</div>
        </div>
      <?php endif; ?>

      <?php if ($error_msg): ?>
        <div class="ih-alert ih-alert--danger">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div><?= htmlspecialchars($error_msg) ?></div>
        </div>
      <?php endif; ?>

      <!-- ── Selector de empresa (solo si hay varias y no se eligió) ── -->
      <?php if ($must_choose_company): ?>
        <form method="post" autocomplete="off">
          <div class="ih-field">
            <label class="ih-field__label" for="company_id">Elegí tu empresa para continuar</label>
            <select class="ih-select" id="company_id" name="company_id" required
                    onchange="this.form.submit()">
              <option value="" disabled selected>Seleccioná…</option>
              <?php foreach ($companies as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>

      <?php elseif ($ssoConfigured && !$show_local): ?>
        <!-- ── SSO configurado: SOLO botones de proveedor ── -->
        <div style="display:flex;flex-direction:column;gap:12px;">

          <?php if ($googleAuthUrl): ?>
            <a class="ih-btn ih-btn--google ih-btn--lg ih-btn--block"
               href="<?= htmlspecialchars($googleAuthUrl) ?>">
              <svg width="18" height="18" viewBox="0 0 48 48" style="flex-shrink:0;"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.31-8.16 2.31-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/><path fill="none" d="M0 0h48v48H0z"/></svg>
              Continuar con Google
            </a>
          <?php endif; ?>

          <?php if ($msAuthUrl): ?>
            <a class="ih-btn ih-btn--microsoft ih-btn--lg ih-btn--block"
               href="<?= htmlspecialchars($msAuthUrl) ?>">
              <svg width="18" height="18" viewBox="0 0 23 23" style="flex-shrink:0;"><path fill="#f25022" d="M0 0h11v11H0z"/><path fill="#00a4ef" d="M12 0h11v11H12z"/><path fill="#7fba00" d="M0 12h11v11H0z"/><path fill="#ffb900" d="M12 12h11v11H12z"/></svg>
              Continuar con Microsoft
            </a>
          <?php endif; ?>

        </div>

        <!-- Opción fallback local (oculta, para emergencias de admin) -->
        <?php if (!$localDisabled): ?>
          <p class="ih-text-xs ih-text-muted" style="text-align:center;margin-top:20px;">
            <a href="?<?= $companyId ? 'company='.$companyId.'&' : '' ?>local=1"
               style="color:var(--ih-gray-400);text-decoration:none;">
              ··· acceso alternativo
            </a>
          </p>
        <?php endif; ?>

      <?php else: ?>
        <!-- ── Sin SSO o ?local=1: formulario usuario/contraseña ── -->

        <?php if ($ssoConfigured): ?>
          <div class="ih-alert ih-alert--info" style="margin-bottom:16px;">
            <i class="bi bi-info-circle-fill"></i>
            <div>Acceso alternativo — normalmente usá el botón SSO de tu empresa.</div>
          </div>
        <?php endif; ?>

        <form method="post" autocomplete="off" novalidate>
          <?php if ($must_choose_company): ?>
            <div class="ih-field">
              <label class="ih-field__label" for="company_id">Empresa <span class="req">*</span></label>
              <select class="ih-select" id="company_id" name="company_id" required>
                <option value="" disabled selected>Seleccioná tu empresa…</option>
                <?php foreach ($companies as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= (isset($_POST['company_id']) && (int)$_POST['company_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <div class="ih-field">
            <label class="ih-field__label" for="username">Usuario</label>
            <input class="ih-input" type="text" id="username" name="username"
                   placeholder="admin" required autofocus
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
          </div>

          <div class="ih-field">
            <label class="ih-field__label" for="password">Contraseña</label>
            <input class="ih-input" type="password" id="password" name="password"
                   placeholder="••••••••" required>
          </div>

          <button class="ih-btn ih-btn--primary ih-btn--lg ih-btn--block" type="submit">
            <i class="bi bi-box-arrow-in-right"></i> Iniciar sesión
          </button>
        </form>

        <?php if ($googleAuthUrl || $msAuthUrl): ?>
          <div class="ih-auth__divider">o</div>
          <?php if ($googleAuthUrl): ?>
            <a class="ih-btn ih-btn--google ih-btn--lg ih-btn--block" href="<?= htmlspecialchars($googleAuthUrl) ?>">
              <i class="bi bi-google"></i> Continuar con Google
            </a>
          <?php endif; ?>
          <?php if ($msAuthUrl): ?>
            <a class="ih-btn ih-btn--microsoft ih-btn--lg ih-btn--block" href="<?= htmlspecialchars($msAuthUrl) ?>">
              <i class="bi bi-microsoft"></i> Continuar con Microsoft
            </a>
          <?php endif; ?>
        <?php endif; ?>

      <?php endif; ?>

    </div>

    <div class="ih-auth__footer">
      © <?= date('Y') ?> INTELLIHELP S.A. · Todos los derechos reservados
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
