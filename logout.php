<?php
/**
 * logout.php - Versión multiempresa
 * -----------------------------------
 * - Cierra sesión de usuario (Google y local)
 * - Limpia todas las variables, incluyendo company_id
 * - Maneja cookies de sesión seguras
 * - Logger opcional
 */

declare(strict_types=1);

// Detectar dominio actual automáticamente
$currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$cookieDomain = str_contains($currentHost, '.') ? $currentHost : '';

// Configurar cookies de sesión seguras
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $cookieDomain,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// -----------------------------------------
// Logger opcional (no romper si no existe)
// -----------------------------------------
$loggerPath = __DIR__ . '/logs/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
} else {
    if (!function_exists('log_event')) {
        function log_event($origin, $type, $msg, $tag = 'auth') { /* silencioso */ }
    }
}

// -----------------------------------------
// Registrar cierre de sesión
// -----------------------------------------
$usuario_log = $_SESSION['user_email'] ?? ($_SESSION['user_id'] ?? 'usuario_desconocido');
log_event($usuario_log, "LOGOUT", "Cierre de sesión desde IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 'auth');

// -----------------------------------------
// Revocar token de Google si existe
// -----------------------------------------
if (isset($_SESSION['access_token']['access_token'])) {
    $accessToken = $_SESSION['access_token']['access_token'];
    $revokeUrl = 'https://accounts.google.com/o/oauth2/revoke?token=' . urlencode($accessToken);
    @file_get_contents($revokeUrl);
}

// -----------------------------------------
// Limpiar variables de sesión
// -----------------------------------------
$_SESSION = [];

// También eliminamos cualquier referencia de multiempresa
unset($_SESSION['company_id'], $_SESSION['tenant'], $_SESSION['tenant_id']);

// -----------------------------------------
// Eliminar cookie de sesión
// -----------------------------------------
if (ini_get("session.use_cookies")) {
    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => '/',
            'domain' => $cookieDomain,
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
}

// Destruir la sesión completamente
session_destroy();

// -----------------------------------------
// Redirigir al login
// -----------------------------------------
header('Location: login.php');
exit;
