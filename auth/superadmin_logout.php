<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/session_guard.php';
require_once __DIR__ . '/../core/security/ithub.php';

app_session_start();

// Si se entró con ITHub, la salida sigue a /oauth/logout de ITHub, que cierra
// la sesión única en todas las apps y vuelve a la pantalla de acceso del panel.
$ithubIdToken = (string)($_SESSION['ithub_id_token'] ?? '');
$porIthub     = ($_SESSION['ithub_cliente'] ?? '') === 'superadmin' && ithub_habilitado('superadmin');

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
if ($porIthub) {
    header('Location: ' . ithub_url_salida('superadmin', $ithubIdToken !== '' ? $ithubIdToken : null,
        ithub_base_url('superadmin') . '/auth/superadmin_login.php?bye=1'));
    exit;
}
header('Location: /auth/superadmin_login.php?bye=1');
exit;
