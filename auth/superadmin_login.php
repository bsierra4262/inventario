<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../vendor/autoload.php';

csrf_start();

// Ya autenticado → directo al dashboard
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

$isFirstRun     = false;
$bootstrapError = '';
$err            = '';
$db             = null;

// -----------------------------------------------------
// 1) Bootstrap users_platform / seed superadmin bsierra
// -----------------------------------------------------
try {
    $db = new MasterDB();

    $db->conn->query("CREATE TABLE IF NOT EXISTS `users_platform` (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        username    VARCHAR(100) NOT NULL,
        password    VARCHAR(255) DEFAULT NULL,
        email       VARCHAR(255) DEFAULT NULL,
        google_sub  VARCHAR(64)  DEFAULT NULL,
        google_pic  VARCHAR(500) DEFAULT NULL,
        role        ENUM('superadmin','support') NOT NULL DEFAULT 'superadmin',
        active      TINYINT(1)  NOT NULL DEFAULT 1,
        last_login  DATETIME    DEFAULT NULL,
        created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Asegurar columnas SSO en instalaciones previas
    foreach ([
        "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `google_sub` VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `google_pic` VARCHAR(500) DEFAULT NULL",
        "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `last_login` DATETIME DEFAULT NULL",
        "ALTER TABLE `users_platform` MODIFY COLUMN `password` VARCHAR(255) DEFAULT NULL",
    ] as $a) { try { $db->conn->query($a); } catch (Throwable $e) {} }

    $cnt = (int)($db->conn->query("SELECT COUNT(*) AS c FROM users_platform")->fetch_assoc()['c'] ?? 0);
    if ($cnt === 0) {
        $isFirstRun = true;
        $u = 'bsierra';
        $h = password_hash('***SECRETO-PURGADO***', PASSWORD_DEFAULT);
        $e = 'bsierra4262@gmail.com';
        $st = $db->conn->prepare("INSERT INTO users_platform (username, password, email, role) VALUES (?, ?, ?, 'superadmin')");
        $st->bind_param('sss', $u, $h, $e);
        $st->execute();
        $st->close();
    }
} catch (Throwable $be) {
    $bootstrapError = $be->getMessage();
}

// -----------------------------------------------------
// 2) Configurar Google SSO desde platform_config (master)
// -----------------------------------------------------
$googleClientId     = '';
$googleClientSecret = '';
$googleRedirectUri  = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/superadmin_login.php';

try {
    if ($db) {
        $db->conn->query("CREATE TABLE IF NOT EXISTS `platform_config` (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            config_key   VARCHAR(100) NOT NULL UNIQUE,
            config_value TEXT NOT NULL,
            updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $res = $db->conn->query("SELECT config_key, config_value FROM platform_config WHERE config_key IN ('sa_google_client_id','sa_google_client_secret','sa_google_redirect_uri')");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if ($r['config_key'] === 'sa_google_client_id')     $googleClientId     = $r['config_value'];
                if ($r['config_key'] === 'sa_google_client_secret') $googleClientSecret = $r['config_value'];
                if ($r['config_key'] === 'sa_google_redirect_uri')  $googleRedirectUri  = $r['config_value'];
            }
        }
    }
} catch (Throwable $e) {}

$googleConfigured = ($googleClientId !== '' && $googleClientSecret !== '');
$googleAuthUrl    = null;

if ($googleConfigured) {
    try {
        $client = new Google_Client();
        $client->setClientId($googleClientId);
        $client->setClientSecret($googleClientSecret);
        $client->setRedirectUri($googleRedirectUri);
        $client->addScope(['email', 'profile', 'openid']);
        $client->setAccessType('online');
        $client->setPrompt('select_account');
        $googleAuthUrl = $client->createAuthUrl();
    } catch (Throwable $e) {
        $googleConfigured = false;
    }
}

// -----------------------------------------------------
// 3) Callback Google
// -----------------------------------------------------
if ($googleConfigured && isset($_GET['code']) && $_GET['code'] !== '' && $db) {
    try {
        $client = new Google_Client();
        $client->setClientId($googleClientId);
        $client->setClientSecret($googleClientSecret);
        $client->setRedirectUri($googleRedirectUri);
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        if (isset($token['error'])) {
            throw new Exception($token['error_description'] ?? 'Google OAuth error');
        }
        $client->setAccessToken($token);

        $oauth2 = new Google_Service_Oauth2($client);
        $info = $oauth2->userinfo->get();

        $sub   = (string)($info->id    ?? '');
        $email = strtolower((string)($info->email ?? ''));
        $name  = (string)($info->name  ?? '');
        $pic   = (string)($info->picture ?? '');

        if ($email === '') {
            throw new Exception('Google no devolvió email.');
        }

        // Buscar usuario superadmin por google_sub o por email
        $st = $db->conn->prepare("SELECT id, active FROM users_platform WHERE (google_sub = ? OR email = ?) AND role = 'superadmin' LIMIT 1");
        $st->bind_param('ss', $sub, $email);
        $st->execute();
        $st->bind_result($uid, $active);
        $found = $st->fetch();
        $st->close();

        if (!$found) {
            $err = "Tu cuenta Google ($email) no está autorizada como superadmin. Contactá al administrador para vincularla.";
        } elseif (!(int)$active) {
            $err = 'Tu cuenta de superadmin está desactivada.';
        } else {
            // Vincular el sub Google si aún no estaba seteado
            $st = $db->conn->prepare("UPDATE users_platform SET google_sub = ?, google_pic = ?, last_login = NOW() WHERE id = ?");
            $st->bind_param('ssi', $sub, $pic, $uid);
            $st->execute();
            $st->close();

            superadmin_login($uid);
            $_SESSION['superadmin_email'] = $email;
            $_SESSION['superadmin_name']  = $name ?: $email;
            $_SESSION['superadmin_pic']   = $pic;
            header('Location: /dashboard/dashboard_superadmin.php');
            exit;
        }
    } catch (Throwable $e) {
        $err = 'Error con Google: ' . $e->getMessage();
    }
}

// -----------------------------------------------------
// 4) Login local (usuario/contraseña)
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    csrf_validate();
    $user = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');

    try {
        if (!$db) $db = new MasterDB();
        $stmt = $db->pquery(
            'SELECT id, password, email FROM users_platform WHERE username = ? AND active = 1 AND role = "superadmin" LIMIT 1',
            's', [$user]
        );
        $stmt->bind_result($uid, $hash, $email);
        if ($stmt->fetch() && $hash && password_verify($pass, $hash)) {
            $stmt->close();
            $db->pquery('UPDATE users_platform SET last_login = NOW() WHERE id = ?', 'i', [$uid]);
            superadmin_login($uid);
            $_SESSION['superadmin_email'] = $email;
            $_SESSION['superadmin_name']  = $user;
            header('Location: /dashboard/dashboard_superadmin.php');
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
        <div class="ih-auth__logo">i</div>
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
            <small>Cambiala apenas ingreses.</small>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($_GET['bye'])): ?>
        <div class="ih-alert ih-alert--success">
          <i class="bi bi-check-circle-fill"></i>
          <div>Tu sesión se cerró correctamente.</div>
        </div>
      <?php endif; ?>

      <?php if ($err): ?>
        <div class="ih-alert ih-alert--danger">
          <i class="bi bi-x-circle-fill"></i>
          <div><?= htmlspecialchars($err) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($googleConfigured): ?>
        <a class="ih-btn ih-btn--google ih-btn--lg ih-btn--block" href="<?= htmlspecialchars($googleAuthUrl) ?>">
          <i class="bi bi-google"></i> Continuar con Google Workspace
        </a>
        <div class="ih-auth__divider">o usá credenciales</div>
      <?php endif; ?>

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

      <?php if (!$googleConfigured): ?>
        <p class="ih-text-xs ih-text-muted ih-text-center ih-mt-4">
          <i class="bi bi-info-circle"></i>
          Google SSO no está configurado. Configurá las credenciales OAuth en
          <em>Integraciones</em> después de ingresar.
        </p>
      <?php endif; ?>
    </div>

    <div class="ih-auth__footer">
      © <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
