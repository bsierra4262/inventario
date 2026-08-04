<?php
/**
 * logout.php — Cierre de sesión TENANT (Asset Vault)
 *
 * - Revoca el token de Google si existe
 * - Borra TODAS las variables de sesión (incluida company_id)
 * - Elimina la cookie de sesión
 * - Destruye la sesión y arranca una limpia
 * - Redirige al login del inventario
 */

declare(strict_types=1);

// Los parámetros de la cookie salen de session_guard.php, los MISMOS que usa el
// login. Antes acá se seteaba `domain => $host`, lo que crea una cookie de
// dominio: para el navegador era una cookie distinta de la host-only del login,
// convivían las dos con el mismo nombre y PHP se quedaba con la que no era.
require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start();

// Logger opcional
$loggerPath = __DIR__ . '/../logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'auth') {}
    }
}

$usuario_log = $_SESSION['user_email'] ?? ($_SESSION['user_id'] ?? 'usuario_desconocido');
log_event($usuario_log, 'LOGOUT', 'Cierre de sesión desde IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 'auth');

// Calcular URL de login ANTES de destruir sesion (preferiendo subdomain sobre id)
require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
$company_id_logout = (int)($_SESSION['company_id'] ?? 0);
$loginUrlAfterLogout = tenant_login_url($company_id_logout, ['bye' => 1]);

// Revocar token de Google si existe
if (!empty($_SESSION['access_token']['access_token'])) {
    $accessToken = $_SESSION['access_token']['access_token'];
    @file_get_contents('https://accounts.google.com/o/oauth2/revoke?token=' . urlencode($accessToken));
}

// Limpiar sesión completa
$_SESSION = [];

// Borrar cookie con los MISMOS parámetros con los que se creó: si el domain no
// coincide, el navegador no la borra y queda una sesión huérfana dando vueltas.
if (ini_get('session.use_cookies')) {
    $params = app_session_cookie_params();
    $params['expires'] = time() - 42000;
    unset($params['lifetime']);
    setcookie(session_name(), '', $params);
}

// Destruir y regenerar
session_destroy();
session_start();
session_regenerate_id(true);
$_SESSION = [];

// Redirigir a la URL ya construida (con subdomain si existe)
header('Location: ' . $loginUrlAfterLogout);
exit;
