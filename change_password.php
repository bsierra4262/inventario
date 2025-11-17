<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// -----------------------------
// DB multiempresa + Logger
// -----------------------------
require_once __DIR__ . '/backend/db.php'; // obtiene $conn del tenant activo

$loggerPath = __DIR__ . '/logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath; // log_event()
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'app') { /* silencioso */ }
    }
}

// -----------------------------
// Guardas de sesión
// -----------------------------
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Nombre real de la tabla de usuarios del tenant (t1_users, t2_users, users, etc.)
$usersTable = tn('users');

// -----------------------------
// Cargar usuario actual (solo para auditoría o reforzar flujos)
// -----------------------------
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    $sql_user = "SELECT id, username, email, password, role FROM `{$usersTable}` WHERE id = ?";
    $stmt_user = $conn->prepare($sql_user);
    $stmt_user->bind_param('i', $user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    $user = $result_user->fetch_assoc();
    $stmt_user->close();

    if (!$user) {
        log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "Usuario no encontrado", "auth");
        header('Location: login.php');
        exit();
    }
} catch (Throwable $e) {
    log_event("UsuarioID:$user_id", "ERROR", "DB error cargando usuario: ".$e->getMessage(), "auth");
    http_response_code(500);
    die('Error de base de datos.');
}

// -----------------------------
// CSRF token
// -----------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// -----------------------------
// POST: Cambiar contraseña
// -----------------------------
$msg_html = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    $posted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $posted_token)) {
        log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "CSRF token inválido", "auth");
        $msg_html = '<div class="alert alert-danger">Operación no autorizada.</div>';
    } else {
        $new_password     = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        // Política mínima: 8+ caracteres, al menos una letra y un número
        $policy_ok = (strlen($new_password) >= 8)
                      && preg_match('/[A-Za-z]/', $new_password)
                      && preg_match('/[0-9]/', $new_password);

        if (!$policy_ok) {
            log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "No cumple política", "auth");
            $msg_html = '<div class="alert alert-danger">La contraseña debe tener al menos 8 caracteres, e incluir letras y números.</div>';
        } elseif ($new_password !== $confirm_password) {
            log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "No coincide confirmación", "auth");
            $msg_html = '<div class="alert alert-danger">Las contraseñas no coinciden.</div>';
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                $sql_update = "UPDATE `{$usersTable}` SET password = ? WHERE id = ?";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param('si', $hashed_password, $user_id);
                $stmt_update->execute();
                $stmt_update->close();

                // Auditoría OK
                log_event(
                    $user['email'] ?? ("UsuarioID:$user_id"),
                    "CAMBIO CONTRASEÑA",
                    "Contraseña cambiada correctamente",
                    "auth"
                );

                // Cerrar sesión COMPLETA (local, multiempresa y Google)
                $accessToken = $_SESSION['access_token']['access_token'] ?? null;
                if ($accessToken) {
                    $revokeUrl = 'https://accounts.google.com/o/oauth2/revoke?token=' . urlencode($accessToken);
                    @file_get_contents($revokeUrl);
                }

                // Limpiar sesión
                $_SESSION = [];
                unset(
                    $_SESSION['company_id'],
                    $_SESSION['tenant'],
                    $_SESSION['tenant_id'],
                    $_SESSION['google_authenticated']
                );
                if (ini_get("session.use_cookies")) {
                    setcookie(session_name(), '', [
                        'expires'  => time() - 42000,
                        'path'     => '/',
                        'domain'   => (str_contains($_SERVER['HTTP_HOST'] ?? '', '.') ? $_SERVER['HTTP_HOST'] : ''),
                        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                }
                session_destroy();

                // Aviso y redirect a login
                echo '<script>
                    alert("Contraseña cambiada exitosamente. Por favor, iniciá sesión nuevamente.");
                    window.location.href = "login.php";
                </script>';
                exit;

            } catch (Throwable $e) {
                log_event("UsuarioID:$user_id", "CAMBIO CONTRASEÑA FALLIDO", "DB error al actualizar: ".$e->getMessage(), "auth");
                $msg_html = '<div class="alert alert-danger">Error al actualizar la contraseña.</div>';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar Contraseña - Intellihelp</title>
    <link href="https://fonts.googleapis.com/css2?family=Saira:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body {
            display:flex;flex-direction:column;justify-content:center;align-items:center;
            min-height:100vh;font-family:'Saira',sans-serif;background-color:#f5f5f5;animation:fadeIn 1s ease-in-out
        }
        .container { opacity:0; transform:translateY(30px); animation:fadeInUp 1s ease-out forwards }
        .form-control,.btn { border-radius:30px; transition:transform .3s ease, box-shadow .3s ease }
        .form-control:focus { transform:translateY(-4px); box-shadow:0 4px 8px rgba(0,0,0,.1) }
        .btn-primary { background-color:#9cc930; border-color:#9cc930 }
        .btn-primary:hover { background-color:#86b329; border-color:#86b329; transform:translateY(-4px) }
        @keyframes fadeInUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
        footer { text-align:center; padding:20px 0; width:100%; background-color:#663399; color:#fff; position:fixed; bottom:0 }
    </style>
</head>
<body>
    <div class="container mt-5">
        <?php if (!empty($msg_html)) { echo $msg_html; } ?>
        <div class="row justify-content-center">
            <div class="col-md-6">
                <h2 class="text-center mb-4">Cambiar Contraseña</h2>
                <form action="change_password.php" method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="mb-3">
                        <label for="new_password" class="form-label">Nueva Contraseña</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8"
                               pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="Mínimo 8 caracteres, con letras y números">
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirmar Contraseña</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8"
                               pattern="(?=.*[A-Za-z])(?=.*\d).{8,}">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Cambiar Contraseña</button>
                </form>
            </div>
        </div>
    </div>

    <footer>© 2025 ITH - Todos los derechos reservados</footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
