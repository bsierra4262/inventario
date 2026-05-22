<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Limpiar variables de sesión COMPLETAS (incluso si había sesión de tenant)
$_SESSION = [];

// Borrar la cookie de sesión
if (ini_get('session.use_cookies')) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $cookieDomain = str_contains($host, '.') ? $host : '';
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

// Destruir sesión
session_destroy();

// Re-arrancar sesión limpia (para que el flash funcione si quisiéramos)
session_start();
session_regenerate_id(true);
$_SESSION = [];

header('Location: /auth/superadmin_login.php?bye=1');
exit;
