<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/session_guard.php';

app_session_start();

// Limpiar variables de sesión COMPLETAS (incluso si había sesión de tenant)
$_SESSION = [];

// Borrar la cookie con los MISMOS parámetros con los que se creó. Antes acá se
// fijaba `domain => $host`, que crea una cookie de dominio: distinta de la
// host-only que usa el login, así que no la borraba y quedaban las dos con el
// mismo nombre. El navegador mandaba ambas y PHP se quedaba con la que no era.
if (ini_get('session.use_cookies')) {
    $params = app_session_cookie_params();
    $params['expires'] = time() - 42000;
    unset($params['lifetime']);
    setcookie(session_name(), '', $params);
}

// Destruir sesión
session_destroy();

// Re-arrancar sesión limpia (para que el flash funcione si quisiéramos)
app_session_start();
session_regenerate_id(true);
$_SESSION = [];

session_write_close();
header('Location: /auth/superadmin_login.php?bye=1');
exit;
