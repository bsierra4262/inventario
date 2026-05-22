<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// -----------------------------
// 0) Logger opcional y vendor
// -----------------------------
$loggerPath = __DIR__ . '/../logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'app') { /* silencioso */ }
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

// -----------------------------
// 1) Resolver empresa antes del tenant
// -----------------------------
require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/db/master_connection.php';

$companies = [];
$masterDbError = '';
try {
    $master = new MasterDB();
    $stmt = $master->pquery('SELECT id, nombre FROM companies WHERE active = 1 ORDER BY nombre ASC');
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $companies[] = $row; }
} catch (Throwable $e) {
    log_event('login.php', 'ERROR', 'Master DB no disponible: '.$e->getMessage(), 'auth');
    $masterDbError = $e->getMessage();
}

// Si master DB no responde → mandar a install (asume primera ejecución)
if ($masterDbError !== '') {
    header('Location: /install.php');
    exit();
}

// Si no hay empresas activas → MOSTRAR pantalla de bloqueo (no enviar a install)
// El install no debe ser accesible para usuarios finales del tenant.
if (empty($companies)) {
    $page_title = 'Acceso restringido';
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
          <h2 class="ih-auth__title">Sin empresas activas</h2>
          <p class="ih-auth__subtitle">Aún no hay ningún cliente habilitado en esta plataforma</p>
        </div>
        <div class="ih-auth__body">
          <div class="ih-alert ih-alert--info">
            <i class="bi bi-info-circle-fill"></i>
            <div>
              El SuperAdmin debe crear primero una empresa cliente desde el
              <strong>Panel SuperAdmin → Nueva empresa</strong>. Ahí se define el
              admin del tenant con email y contraseña.
            </div>
          </div>
          <a href="/auth/superadmin_login.php" class="ih-btn ih-btn--primary ih-btn--block">
            <i class="bi bi-shield-lock"></i> Ir al Panel SuperAdmin
          </a>
        </div>
        <div class="ih-auth__footer">
          © <?= date('Y') ?> INTELLIHELP S.A. · Asset Vault
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/../includes/layout/foot.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['company_id']) && ctype_digit($_POST['company_id'])) {
    $_SESSION['company_id'] = (int)$_POST['company_id'];
}

$must_choose_company = false;
if (empty($_SESSION['company_id'])) {
    if (count($companies) === 1) {
        $_SESSION['company_id'] = (int)$companies[0]['id'];
    } else {
        $must_choose_company = true;
    }
}

// -----------------------------
// 2) Conexión tenant
// -----------------------------
require_once __DIR__ . '/../backend/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $conn->set_charset('utf8mb4'); } catch (Throwable $e) {}

// -----------------------------
// 3) Configuración Google Client
// -----------------------------
$googleClientId     = '';
$googleClientSecret = '';
$googleRedirectUri  = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/login.php';

try {
    $cfgTable = tn('cloud_config');
    $cfgRes = $conn->query("SELECT config_key, config_value FROM `{$cfgTable}` WHERE provider = 'google'");
    if ($cfgRes) {
        while ($cfgRow = $cfgRes->fetch_assoc()) {
            if ($cfgRow['config_key'] === 'client_id')     $googleClientId     = $cfgRow['config_value'];
            if ($cfgRow['config_key'] === 'client_secret') $googleClientSecret = $cfgRow['config_value'];
            if ($cfgRow['config_key'] === 'redirect_uri')  $googleRedirectUri  = $cfgRow['config_value'];
        }
    }
} catch (Throwable $e) {}

$googleConfigured = ($googleClientId !== '' && $googleClientSecret !== '');
$client = null;
$googleAuthUrl = null;

if ($googleConfigured) {
    $client = new Google_Client();
    $client->setClientId($googleClientId);
    $client->setClientSecret($googleClientSecret);
    $client->setRedirectUri($googleRedirectUri);
    $client->addScope([
        Google_Service_Drive::DRIVE,
        Google_Service_Drive::DRIVE_FILE,
        Google_Service_Drive::DRIVE_METADATA_READONLY,
        Google_Service_Drive::DRIVE_APPDATA,
        'email',
        'profile'
    ]);
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');
    $googleAuthUrl = $client->createAuthUrl();
}

// -----------------------------
// 4) GOOGLE LOGIN CALLBACK
// -----------------------------
$error_msg = '';

if ($googleConfigured && isset($_GET['code']) && $_GET['code'] !== '') {
    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        if (!isset($token['error'])) {
            $_SESSION['access_token'] = $token;
            $client->setAccessToken($token);

            $oauth2 = new Google_Service_Oauth2($client);
            $userInfo = $oauth2->userinfo->get();

            $_SESSION['google_authenticated'] = true;
            $_SESSION['user_email']   = $userInfo->email;
            $_SESSION['user_name']    = $userInfo->name;
            $_SESSION['user_picture'] = $userInfo->picture;

            $device_hash = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
            $correo = $userInfo->email;

            if (empty($_SESSION['company_id']) && count($companies) === 1) {
                $_SESSION['company_id'] = (int)$companies[0]['id'];
            }
            if (empty($_SESSION['company_id'])) {
                $error_msg = 'Seleccioná una empresa para continuar.';
            } else {
                if (!empty($_SESSION['user_id'])) {
                    $sql = "UPDATE " . tn('users') . " SET email = ? WHERE id = ? AND email = 'admin@inventario.local'";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('si', $correo, $_SESSION['user_id']);
                    $stmt->execute();
                    $stmt->close();
                    log_event($correo, "LOGIN GOOGLE", "Correo asociado a user_id existente.", 'auth');
                } else {
                    $sql = "SELECT id FROM " . tn('users') . " WHERE email = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('s', $correo);
                    $stmt->execute();
                    $stmt->bind_result($user_id);
                    if ($stmt->fetch()) {
                        $_SESSION['user_id'] = (int)$user_id;
                        $stmt->close();
                    } else {
                        $stmt->close();
                        $username = explode('@', $correo)[0];
                        $rand_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                        $sql = "INSERT INTO " . tn('users') . " (username, password, email, role) VALUES (?, ?, ?, 'user')";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param('sss', $username, $rand_pass, $correo);
                        $stmt->execute();
                        $_SESSION['user_id'] = (int)$stmt->insert_id;
                        $stmt->close();
                        log_event($correo, "LOGIN GOOGLE", "Nuevo usuario creado.", 'auth');
                    }
                }

                $devicesTable = tn('user_devices');
                $checkUD = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($devicesTable) . "'");
                if ($checkUD && $checkUD->num_rows > 0) {
                    $sql = "INSERT IGNORE INTO " . $devicesTable . " (user_id, google_email, device_hash) VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $uid = (int)$_SESSION['user_id'];
                    $stmt->bind_param('iss', $uid, $correo, $device_hash);
                    $stmt->execute();
                    $stmt->close();
                }

                log_event($correo, "LOGIN GOOGLE", "Acceso desde IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 'auth');
                header('Location: /inventario/index.php');
                exit();
            }
        } else {
            $error_msg = 'Error de autenticación con Google: ' . ($token['error_description'] ?? 'desconocido');
            log_event("usuario_desconocido", "ERROR", "Google fetch token: " . json_encode($token), 'auth');
        }
    } catch (Throwable $e) {
        $error_msg = 'Error de autenticación con Google. El administrador debe revisar el log.';
        log_event("usuario_desconocido", "ERROR", "Excepción Google: " . $e->getMessage(), 'auth');
    }
}

// -----------------------------
// 5) LOGIN LOCAL
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['username'], $_POST['password'])
    && !isset($_GET['code'])
) {
    if ($must_choose_company && empty($_POST['company_id'])) {
        $error_msg = 'Seleccioná una empresa para continuar.';
    } else {
        $username = trim((string)$_POST['username']);
        $password = (string)$_POST['password'];

        $user = null;
        try {
            $sql = "SELECT id, password, role, email FROM " . tn('users') . " WHERE username = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Throwable $e) {
            $error_msg = 'Error interno al verificar credenciales.';
            log_event('login.php', 'ERROR', 'DB login local: '.$e->getMessage(), 'auth');
        }

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = (int)$user['id'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            log_event($user['email'] ?? "id:{$user['id']}", "LOGIN LOCAL", "Acceso exitoso IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 'auth');

            // Verificar si debe cambiar contraseña obligatoriamente
            $mustChange = false;
            try {
                $mcStmt = $conn->prepare("SELECT must_change_password FROM " . tn('users') . " WHERE id = ?");
                $mcStmt->bind_param('i', (int)$user['id']);
                $mcStmt->execute();
                $mcRow = $mcStmt->get_result()->fetch_assoc();
                $mcStmt->close();
                $mustChange = !empty($mcRow['must_change_password']);
            } catch (Throwable) {
                // Columna no existe aún (tenant viejo) → no forzar
            }

            if ($mustChange) {
                $_SESSION['force_password_change'] = true;
                header('Location: /auth/change_password.php');
                exit();
            }

            if ($googleConfigured && !isset($_SESSION['google_authenticated'])) {
                header('Location: ' . $googleAuthUrl);
                exit();
            }

            header('Location: /inventario/index.php');
            exit();
        } else if (!$error_msg) {
            $error_msg = 'Credenciales inválidas.';
            log_event("Usuario: $username", "LOGIN FALLIDO", "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 'auth');
        }
    }
}

$page_title = 'Iniciar sesión';
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

      <?php if ($error_msg): ?>
        <div class="ih-alert ih-alert--danger">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div><?= htmlspecialchars($error_msg) ?></div>
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
          <input class="ih-input" type="text" id="username" name="username" placeholder="admin" required autofocus>
        </div>

        <div class="ih-field">
          <label class="ih-field__label" for="password">Contraseña</label>
          <input class="ih-input" type="password" id="password" name="password" placeholder="••••••••" required>
        </div>

        <button class="ih-btn ih-btn--primary ih-btn--lg ih-btn--block" type="submit">
          <i class="bi bi-box-arrow-in-right"></i> Iniciar sesión
        </button>
      </form>

      <?php if ($googleConfigured): ?>
        <div class="ih-auth__divider">o</div>
        <?php if ($must_choose_company && empty($_SESSION['company_id'])): ?>
          <button class="ih-btn ih-btn--google ih-btn--lg ih-btn--block" disabled>
            <i class="bi bi-google"></i> Elegí empresa primero
          </button>
        <?php else: ?>
          <a class="ih-btn ih-btn--google ih-btn--lg ih-btn--block" href="<?= htmlspecialchars($googleAuthUrl) ?>">
            <i class="bi bi-google"></i> Continuar con Google
          </a>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="ih-auth__footer">
      © <?= date('Y') ?> INTELLIHELP S.A. · Todos los derechos reservados
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout/foot.php'; ?>
