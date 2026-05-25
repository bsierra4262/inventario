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

// Iniciar sesión con parámetros seguros
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$cookieDomain = str_contains($host, '.') ? $host : '';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => $cookieDomain,
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

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

// Guardar company_id antes de destruir la sesión para redirigir de vuelta a la misma empresa
$company_id_logout = (int)($_SESSION['company_id'] ?? 0);

// Revocar token de Google si existe
if (!empty($_SESSION['access_token']['access_token'])) {
    $accessToken = $_SESSION['access_token']['access_token'];
    @file_get_contents('https://accounts.google.com/o/oauth2/revoke?token=' . urlencode($accessToken));
}

// Limpiar sesión completa
$_SESSION = [];

// Borrar cookie
if (ini_get('session.use_cookies')) {
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => '/',
            'domain'   => $cookieDomain,
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

// Destruir y regenerar
session_destroy();
session_start();
session_regenerate_id(true);
$_SESSION = [];

// Redirigir a la misma empresa para que el usuario pueda volver a ingresar
$loginUrl = '/auth/login.php?bye=1';
if ($company_id_logout > 0) {
    $loginUrl .= '&company=' . $company_id_logout;
}
header('Location: ' . $loginUrl);
exit;
