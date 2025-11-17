<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// -----------------------------
// 0) Logger opcional y vendor
// -----------------------------
$loggerPath = __DIR__ . '/logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath; // define log_event()
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'app') { /* silencioso */ }
    }
}

require_once __DIR__ . '/vendor/autoload.php';

// --------------------------------------------------------------------
// 1) Resolver empresa (company_id) ANTES de abrir la conexión del tenant
// --------------------------------------------------------------------
require_once __DIR__ . '/core/config/platform.php';
require_once __DIR__ . '/core/db/master_connection.php';

$master = new MasterDB();
$companies = [];
try {
    $stmt = $master->pquery('SELECT id, nombre FROM companies ORDER BY nombre ASC');
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $companies[] = $row; }
} catch (Throwable $e) {
    log_event('login.php', 'ERROR', 'No se pudieron leer empresas de MASTER: '.$e->getMessage(), 'auth');
    http_response_code(500);
    die('Error leyendo empresas desde la DB master.');
}

// Si viene selección por POST, fijamos el tenant elegido
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['company_id']) && ctype_digit($_POST['company_id'])) {
    $_SESSION['company_id'] = (int)$_POST['company_id'];
}

// Si no hay company en sesión:
//  - Si hay una sola empresa => usarla
//  - Si hay varias => mostrar selector en el formulario
$must_choose_company = false;
if (empty($_SESSION['company_id'])) {
    if (count($companies) === 1) {
        $_SESSION['company_id'] = (int)$companies[0]['id'];
    } else {
        $must_choose_company = true;
    }
}

// --------------------------------------------------------------------
// 2) Conexión del tenant (multiempresa) una vez resuelto company_id
// --------------------------------------------------------------------
require_once __DIR__ . '/backend/db.php'; // obtiene $conn del tenant activo (o cae en install si falta)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $conn->set_charset('utf8mb4'); } catch (Throwable $e) { /* ya configurado */ }

// --------------------------------------------------------------------
// 3) Configuración Google Client
// --------------------------------------------------------------------
$client = new Google_Client();
$client->setClientId('777999666864-jkesvkp9b4sh5sf9odpjmn3k46tsjtmn.apps.googleusercontent.com');
$client->setClientSecret('TU_CLIENT_SECRET_DE_GOOGLE');
$client->setRedirectUri('https://desarrollo.intellihelp.tech/login.php');
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

// URL de autenticación Google
$googleAuthUrl = $client->createAuthUrl();

// --------------------------------------------------------------------
// 4) PROCESO GOOGLE LOGIN
// --------------------------------------------------------------------
if (isset($_GET['code']) && $_GET['code'] !== '') {
    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

        if (!isset($token['error'])) {
            // Token OK
            $_SESSION['access_token'] = $token;
            $client->setAccessToken($token);

            $oauth2   = new Google_Service_Oauth2($client);
            $userInfo = $oauth2->userinfo->get();

            $_SESSION['google_authenticated'] = true;
            $_SESSION['user_email']    = $userInfo->email;
            $_SESSION['user_name']     = $userInfo->name;
            $_SESSION['user_picture']  = $userInfo->picture;

            $device_hash = hash(
                'sha256',
                ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')
            );
            $correo = $userInfo->email;

            // Si no hay empresa elegida todavía (caso borde), intentamos fijar la 1ª
            if (empty($_SESSION['company_id']) && count($companies) === 1) {
                $_SESSION['company_id'] = (int)$companies[0]['id'];
            }
            if (empty($_SESSION['company_id'])) {
                // No se puede continuar sin empresa
                log_event($correo, 'ERROR', 'Google login sin company_id en sesión', 'auth');
                echo "<script>alert('Seleccioná una empresa para continuar.');window.location='login.php';</script>";
                exit;
            }

            // --- Cuenta local en el tenant ---
            if (!empty($_SESSION['user_id'])) {
                // Ya hay sesión local: actualizo email placeholder si corresponde
                $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ? AND email = 'admin@inventario.local'");
                $stmt->bind_param('si', $correo, $_SESSION['user_id']);
                $stmt->execute();
                $stmt->close();
                $user_id = (int)$_SESSION['user_id'];
                log_event($correo, "LOGIN GOOGLE", "Correo real asociado a user_id existente.", 'auth');
            } else {
                // Buscar por correo
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->bind_param('s', $correo);
                $stmt->execute();
                $stmt->bind_result($user_id);
                if ($stmt->fetch()) {
                    $_SESSION['user_id'] = (int)$user_id;
                    $stmt->close();
                    log_event($correo, "LOGIN GOOGLE", "Usuario existente con este correo.", 'auth');
                } else {
                    $stmt->close();
                    // Crear nuevo usuario
                    $username  = explode('@', $correo)[0];
                    $rand_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'user')");
                    $stmt->bind_param('sss', $username, $rand_pass, $correo);
                    $stmt->execute();
                    $_SESSION['user_id'] = (int)$stmt->insert_id;
                    $stmt->close();
                    log_event($correo, "LOGIN GOOGLE", "Nuevo usuario creado en tenant.", 'auth');
                }
            }

            // Registrar dispositivo (si existe la tabla)
            $has_ud = $conn->query("SHOW TABLES LIKE 'user_devices'");
            if ($has_ud && $has_ud->num_rows > 0) {
                $stmt = $conn->prepare("INSERT IGNORE INTO user_devices (user_id, google_email, device_hash) VALUES (?, ?, ?)");
                $uid  = (int)$_SESSION['user_id'];
                $stmt->bind_param('iss', $uid, $correo, $device_hash);
                $stmt->execute();
                $stmt->close();
            }

            log_event($correo, "LOGIN GOOGLE", "Inicio con Google desde IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 'auth');
            header('Location: index.php');
            exit();

        } else {
            // Google nos devolvió un error en el token
            $err_code = $token['error'] ?? 'unknown';
            $err_desc = $token['error_description'] ?? 'sin descripción';
            log_event(
                "usuario_desconocido",
                "ERROR",
                "Error Google (fetch token): $err_code - $err_desc",
                'auth'
            );

            $msg = 'Error de autenticación con Google. Detalle: ' . $err_desc;
            echo "<script>alert(" . json_encode($msg, JSON_UNESCAPED_UNICODE) . ");window.location='login.php';</script>";
            exit;
        }
    } catch (Throwable $e) {
        // Excepción en la librería / network / etc.
        log_event("usuario_desconocido", "ERROR", "Excepción Google: ".$e->getMessage(), 'auth');
        $msg = 'Error de autenticación con Google. El administrador debe revisar el log.';
        echo "<script>alert(" . json_encode($msg, JSON_UNESCAPED_UNICODE) . ");window.location='login.php';</script>";
        exit;
    }
}

// --------------------------------------------------------------------
// 5) PROCESO LOGIN LOCAL (usuario/contraseña)
// --------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['username'], $_POST['password'])
    && !isset($_GET['code'])
) {

    // Si había que elegir empresa y no vino, cortar
    if ($must_choose_company && empty($_POST['company_id'])) {
        echo '<script>alert("Seleccioná una empresa para continuar.");</script>';
    } else {
        $username = trim((string)$_POST['username']);
        $password = (string)$_POST['password'];

        try {
            // Esta parte antes podía lanzar excepción y devolver 500
            $stmt = $conn->prepare("SELECT id, password, role, email FROM users WHERE username = ?");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user   = $result->fetch_assoc();
            $stmt->close();
        } catch (Throwable $e) {
            // Capturamos el error de DB para evitar HTTP 500
            log_event('login.php', 'ERROR', 'Error DB en login local: '.$e->getMessage(), 'auth');
            echo '<script>alert("Error interno al verificar tus credenciales. El administrador debe revisar el log.");</script>';
            $user = null;
        }

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = (int)$user['id'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            log_event($user['email'], "LOGIN LOCAL", "Acceso exitoso desde IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 'auth');

            // Si es admin con pass por defecto -> forzar cambio
            if ($user['role'] === 'admin' && password_verify('admin', $user['password'])) {
                header('Location: change_password.php');
                exit();
            }

            // Si no autenticó por Google aún, lo enviamos a Google (política actual)
            if (!isset($_SESSION['google_authenticated'])) {
                header('Location: ' . $googleAuthUrl);
                exit();
            } else {
                header('Location: index.php');
                exit();
            }
        } else {
            log_event("Usuario: $username", "LOGIN FALLIDO", "Credenciales inválidas desde IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 'auth');
            echo '<script>alert("Credenciales inválidas.");</script>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Intellihelp</title>
    <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body{display:flex;flex-direction:column;justify-content:center;align-items:center;min-height:100vh;font-family:'Saira',sans-serif;background-color:#f5f5f5;animation:fadeIn 1s ease-in-out}
        .container{opacity:0;transform:translateY(30px);animation:fadeInUp 1s ease-out forwards}
        .form-control,.btn{border-radius:30px;transition:transform .3s ease,box-shadow .3s ease}
        .form-control:focus{transform:translateY(-4px);box-shadow:0 4px 8px rgba(0,0,0,.1)}
        .btn-primary{background-color:#9cc930;border-color:#9cc930}
        .btn-primary:hover{background-color:#86b329;border-color:#86b329;transform:translateY(-4px)}
        @keyframes fadeInUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
        @keyframes fadeIn{from{opacity:0}to{opacity:1}}
        footer{text-align:center;padding:20px 0;width:100%;background-color:#663399;color:#fff;position:fixed;bottom:0}
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <h2 class="text-center mb-4">Iniciar Sesión</h2>
                <form action="login.php" method="POST" class="mb-3">
                    <?php if ($must_choose_company): ?>
                        <div class="mb-3">
                            <label for="company_id" class="form-label">Empresa</label>
                            <select class="form-control" id="company_id" name="company_id" required>
                                <option value="" disabled selected>Seleccioná la empresa...</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= htmlspecialchars((string)$c['id']) ?>"
                                        <?= (isset($_POST['company_id']) && (int)$_POST['company_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="username" class="form-label">Usuario</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Contraseña</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Iniciar Sesión</button>
                </form>

                <div class="d-grid">
                    <?php if ($must_choose_company && empty($_SESSION['company_id'])): ?>
                        <button type="button" class="btn btn-outline-secondary" disabled>Ingresar con Google (elegí empresa)</button>
                    <?php else: ?>
                        <a class="btn btn-outline-primary" href="<?= htmlspecialchars($googleAuthUrl) ?>">
                            Ingresar con Google
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <footer>© 2025 ITH - Todos los derechos reservados</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
