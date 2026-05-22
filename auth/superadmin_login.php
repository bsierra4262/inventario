<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../vendor/autoload.php';

csrf_start(); // arranca sesión

// Ya autenticado → directo al resumen
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_resumen.php');
    exit;
}

$isFirstRun     = false;
$bootstrapError = '';
$err            = '';
$db             = null;

// ─────────────────────────────────────────────────────────────────────────────
// 1) Bootstrap users_platform / seed superadmin bsierra
// ─────────────────────────────────────────────────────────────────────────────
try {
    $db = new MasterDB();

    $db->conn->query("CREATE TABLE IF NOT EXISTS `users_platform` (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        username    VARCHAR(100) NOT NULL,
        password    VARCHAR(255) DEFAULT NULL,
        email       VARCHAR(255) DEFAULT NULL,
        google_sub  VARCHAR(64)  DEFAULT NULL,
        google_pic  VARCHAR(500) DEFAULT NULL,
        ms_sub      VARCHAR(64)  DEFAULT NULL,
        ms_pic      VARCHAR(500) DEFAULT NULL,
        role        ENUM('superadmin','support') NOT NULL DEFAULT 'superadmin',
        active      TINYINT(1)  NOT NULL DEFAULT 1,
        last_login  DATETIME    DEFAULT NULL,
        created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `google_sub` VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `google_pic` VARCHAR(500) DEFAULT NULL",
        "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `ms_sub`     VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `ms_pic`     VARCHAR(500) DEFAULT NULL",
        "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `last_login` DATETIME DEFAULT NULL",
        "ALTER TABLE `users_platform` MODIFY COLUMN `password` VARCHAR(255) DEFAULT NULL",
    ] as $a) { try { $db->conn->query($a); } catch (Throwable) {} }

    $cnt = (int)($db->conn->query("SELECT COUNT(*) AS c FROM users_platform")->fetch_assoc()['c'] ?? 0);
    if ($cnt === 0) {
        $isFirstRun = true;
        $u = 'bsierra';
        $h = password_hash('***SECRETO-PURGADO***', PASSWORD_DEFAULT);
        $e = 'bsierra@intellihelp.tech';
        $st = $db->conn->prepare("INSERT INTO users_platform (username, password, email, role) VALUES (?, ?, ?, 'superadmin')");
        $st->bind_param('sss', $u, $h, $e);
        $st->execute();
        $st->close();
    } else {
        try {
            $db->conn->query("UPDATE users_platform SET email = 'bsierra@intellihelp.tech' WHERE username = 'bsierra' AND email = 'bsierra4262@gmail.com'");
        } catch (Throwable) {}
    }
} catch (Throwable $be) {
    $bootstrapError = $be->getMessage();
}

// ─────────────────────────────────────────────────────────────────────────────
// 2) URI de callback único (registrar esta URI en Google Console / Azure)
// ─────────────────────────────────────────────────────────────────────────────
$callbackUri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/superadmin_callback.php';

// ─────────────────────────────────────────────────────────────────────────────
// 3) Cargar config SSO (Google + Microsoft) desde platform_config
// ─────────────────────────────────────────────────────────────────────────────
$googleClientId     = '';
$googleClientSecret = '';
$googleHd           = '';
$msClientId         = '';
$msClientSecret     = '';
$msTenantId         = 'common';
$msHd               = '';

try {
    if ($db) {
        $db->conn->query("CREATE TABLE IF NOT EXISTS `platform_config` (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            config_key   VARCHAR(100) NOT NULL UNIQUE,
            config_value TEXT NOT NULL,
            updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $res = $db->conn->query("SELECT config_key, config_value FROM platform_config WHERE config_key IN (
            'sa_google_client_id','sa_google_client_secret','sa_google_hd',
            'sa_ms_client_id','sa_ms_client_secret','sa_ms_tenant_id','sa_ms_hd'
        )");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                match ($r['config_key']) {
                    'sa_google_client_id'     => $googleClientId     = $r['config_value'],
                    'sa_google_client_secret' => $googleClientSecret = $r['config_value'],
                    'sa_google_hd'            => $googleHd           = $r['config_value'],
                    'sa_ms_client_id'         => $msClientId         = $r['config_value'],
                    'sa_ms_client_secret'     => $msClientSecret     = $r['config_value'],
                    'sa_ms_tenant_id'         => $msTenantId         = $r['config_value'],
                    'sa_ms_hd'                => $msHd               = $r['config_value'],
                    default                   => null,
                };
            }
        }
    }
} catch (Throwable) {}

$googleConfigured = ($googleClientId !== '' && $googleClientSecret !== '');
$msConfigured     = ($msClientId     !== '' && $msClientSecret     !== '');
$ssoConfigured    = $googleConfigured || $msConfigured;

// ─────────────────────────────────────────────────────────────────────────────
// 4) Generar state OAuth y URLs de autenticación
// ─────────────────────────────────────────────────────────────────────────────
$oauthRand = bin2hex(random_bytes(16));
$_SESSION['oauth_state_rand'] = $oauthRand;

$googleAuthUrl = null;
if ($googleConfigured) {
    try {
        $client = new Google_Client();
        $client->setClientId($googleClientId);
        $client->setClientSecret($googleClientSecret);
        $client->setRedirectUri($callbackUri);
        $client->addScope(['email', 'profile', 'openid']);
        $client->setAccessType('online');
        $client->setPrompt('select_account');
        if ($googleHd !== '') { $client->setHostedDomain($googleHd); }
        $client->setState('g:' . $oauthRand);
        $googleAuthUrl = $client->createAuthUrl();
    } catch (Throwable) {
        $googleConfigured = false;
    }
}

$msAuthUrl = null;
if ($msConfigured) {
    try {
        $tenantSlug = ($msTenantId !== '' ? $msTenantId : 'common');
        $msParams   = [
            'client_id'     => $msClientId,
            'response_type' => 'code',
            'redirect_uri'  => $callbackUri,
            'scope'         => 'openid profile email User.Read',
            'response_mode' => 'query',
            'state'         => 'm:' . $oauthRand,
            'prompt'        => 'select_account',
        ];
        if ($msHd !== '') { $msParams['domain_hint'] = $msHd; }
        $msAuthUrl = "https://login.microsoftonline.com/{$tenantSlug}/oauth2/v2.0/authorize?" . http_build_query($msParams);
    } catch (Throwable) {
        $msConfigured = false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 5) Flash error proveniente del callback
// ─────────────────────────────────────────────────────────────────────────────
if (!empty($_SESSION['sa_login_error'])) {
    $err = $_SESSION['sa_login_error'];
    unset($_SESSION['sa_login_error']);
}

// ─────────────────────────────────────────────────────────────────────────────
// 6) Login local (usuario/contraseña) — solo disponible cuando NO hay SSO
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    csrf_validate();
    $user = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');

    try {
        if (!$db) $db = new MasterDB();
        $stmt = $db->pquery(
            'SELECT id, password, email, role FROM users_platform WHERE username = ? AND active = 1 LIMIT 1',
            's', [$user]
        );
        $stmt->bind_result($uid, $hash, $email, $role);
        if ($stmt->fetch() && $hash && password_verify($pass, $hash)) {
            $stmt->close();
            $db->pquery('UPDATE users_platform SET last_login = NOW() WHERE id = ?', 'i', [$uid]);
            superadmin_login($uid, $role);
            $_SESSION['superadmin_email'] = $email;
            $_SESSION['superadmin_name']  = $user;
            if (!$ssoConfigured && $role === 'superadmin') {
                $_SESSION['pending_sso_setup'] = true;
                header('Location: /dashboard/superadmin_integraciones.php');
            } else {
                header('Location: /dashboard/dashboard_resumen.php');
            }
            exit;
        }
        $stmt->close();
        $err = 'Usuario o contraseña inválidos.';
    } catch (Throwable $ex) {
        $err = 'Error del sistema: ' . htmlspecialchars($ex->getMessage());
    }
}

$page_title = 'Acceso SuperAdmin';
require __DIR__ . '/../includes/layout/head.php';
?>

<div class="ih-auth">
  <div class="ih-auth__card ih-anim-fade">
    <div class="ih-auth__header">
      <div class="ih-auth__brand">
        <div class="ih-auth__logo"><?= platform_logo_html() ?></div>
        <div class="ih-auth__brand-text">
          <strong>INTELLIHELP</strong>
          <span>Panel SuperAdmin</span>
        </div>
      </div>
      <h2 class="ih-auth__title">Acceso restringido</h2>
      <p class="ih-auth__subtitle">Plataforma de gestión Asset Vault</p>
    </div>

    <div class="ih-auth__body">

      <?php if ($bootstrapError): ?>
        <div class="ih-alert ih-alert--danger">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div>
            <div class="ih-alert__title">Error de infraestructura</div>
            <?= htmlspecialchars($bootstrapError) ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($isFirstRun): ?>
        <div class="ih-alert ih-alert--warning">
          <i class="bi bi-shield-lock-fill"></i>
          <div>
            <div class="ih-alert__title">Primera ejecución</div>
            Se creó el superadmin inicial:<br>
            Usuario: <code>bsierra</code> · Contraseña: <code>***SECRETO-PURGADO***</code><br>
            <small>Al ingresar serás redirigido a configurar SSO.</small>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($_GET['bye'])): ?>
        <div class="ih-alert ih-alert--success">
          <i class="bi bi-check-circle-fill"></i>
          <div>Tu sesión se cerró correctamente.</div>
        </div>
      <?php endif; ?>

      <?php if (!empty($_GET['sso_configured'])): ?>
        <div class="ih-alert ih-alert--success">
          <i class="bi bi-shield-check-fill"></i>
          <div>
            <strong>SSO configurado correctamente.</strong><br>
            Ingresá con tu cuenta corporativa.
          </div>
        </div>
      <?php endif; ?>

      <?php if ($err): ?>
        <div class="ih-alert ih-alert--danger">
          <i class="bi bi-x-circle-fill"></i>
          <div><?= htmlspecialchars($err) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($ssoConfigured): ?>
        <!-- SSO configurado: solo acceso con botones corporativos -->
        <div class="ih-flex ih-flex-col ih-gap-3">
          <?php if ($googleConfigured && $googleAuthUrl): ?>
            <a class="ih-btn ih-btn--google ih-btn--lg ih-btn--block" href="<?= htmlspecialchars($googleAuthUrl) ?>">
              <i class="bi bi-google"></i> Continuar con Google Workspace
              <?php if ($googleHd !== ''): ?>
                <span class="ih-text-xs" style="margin-left:6px;opacity:.75">(@<?= htmlspecialchars($googleHd) ?>)</span>
              <?php endif; ?>
            </a>
          <?php endif; ?>
          <?php if ($msConfigured && $msAuthUrl): ?>
            <a class="ih-btn ih-btn--microsoft ih-btn--lg ih-btn--block" href="<?= htmlspecialchars($msAuthUrl) ?>">
              <i class="bi bi-microsoft"></i> Continuar con Microsoft 365
              <?php if ($msHd !== ''): ?>
                <span class="ih-text-xs" style="margin-left:6px;opacity:.75">(@<?= htmlspecialchars($msHd) ?>)</span>
              <?php endif; ?>
            </a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <!-- Sin SSO: formulario local (solo hasta que se configure SSO) -->
        <form method="post" autocomplete="off">
          <?php csrf_input(); ?>
          <div class="ih-field">
            <label class="ih-field__label" for="username">Usuario</label>
            <input class="ih-input" type="text" id="username" name="username" placeholder="bsierra" required autofocus>
          </div>
          <div class="ih-field">
            <label class="ih-field__label" for="password">Contraseña</label>
            <input class="ih-input" type="password" id="password" name="password" placeholder="••••••••" required>
          </div>
          <button class="ih-btn ih-btn--primary ih-btn--lg ih-btn--block" type="submit">
            <i class="bi bi-box-arrow-in-right"></i> Ingresar
          </button>
        </form>
        <p class="ih-text-xs ih-text-muted ih-text-center ih-mt-4">
          <i class="bi bi-shield-exclamation"></i>
          Al ingresar se te pedirá configurar Google Workspace o Microsoft 365 SSO.
        </p>
      <?php endif; ?>

    </div>

    <div class="ih-auth__footer">
      © <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
