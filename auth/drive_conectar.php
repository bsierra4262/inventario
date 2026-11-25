<?php
/**
 * auth/drive_conectar.php — «Conectar Google Drive» para quien entró con ITHub.
 *
 * Los archivos de los activos viven en el Drive de la empresa, y el permiso de
 * Drive salía del login con Google (el access_token quedaba en la sesión).
 * Con ITHub, Google ya no es el login, así que el permiso se pide acá, una vez
 * por sesión, con las mismas credenciales de Google de la empresa.
 *
 * Sólo para alguien que ya está adentro, y Google tiene que devolver la MISMA
 * cuenta de la sesión: lo comprueba superadmin_callback.php (estado `td:`),
 * que es la dirección de vuelta ya registrada en Google. No toca el login ni
 * crea sesiones.
 *
 *   /auth/drive_conectar.php?volver=/inventario/agregar.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_guard.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';
require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
require_once __DIR__ . '/../includes/pantalla_aviso.php';
require_once __DIR__ . '/../vendor/autoload.php';

app_session_start();

$cid   = (int)($_SESSION['company_id'] ?? 0);
$email = (string)($_SESSION['user_email'] ?? '');
if (empty($_SESSION['user_id']) || $cid <= 0 || (int)($_SESSION['auth_company_id'] ?? 0) !== $cid || $email === '') {
    app_session_cerrar_y_redirigir(tenant_login_url($cid));
}

// A dónde volver después: sólo una ruta de este mismo sitio.
$volver = (string)($_GET['volver'] ?? '');
if (!preg_match('#^/(?!/)[A-Za-z0-9_\-./?=&%]*$#', $volver)) { $volver = '/inventario/index.php'; }

// Credenciales de Google: las de la plataforma, pisadas por las de la empresa.
$clientId     = GOOGLE_CLIENT_ID;
$clientSecret = GOOGLE_CLIENT_SECRET;
$redirectUri  = GOOGLE_REDIRECT_URI ?: ithub_drive_callback_por_omision();
try {
    ['conn' => $tconn, 'prefix' => $pf] = tenant_conn_by_company($cid);
    $res = $tconn->query("SELECT config_key, config_value FROM `{$pf}cloud_config` WHERE provider = 'google'");
    while ($res && ($row = $res->fetch_assoc())) {
        match ((string)$row['config_key']) {
            'client_id'     => $clientId     = (string)$row['config_value'],
            'client_secret' => $clientSecret = (string)$row['config_value'],
            'redirect_uri'  => $redirectUri  = (string)$row['config_value'],
            default         => null,
        };
    }
} catch (Throwable $e) {
    pantalla_aviso([
        'titulo'   => 'No se pudo conectar Google Drive',
        'detalle'  => 'La base de la empresa no respondió. Volvé a intentar en unos segundos.',
        'codigo'   => 503,
        'acciones' => [['texto' => 'Volver', 'href' => $volver, 'icono' => 'arrow-left']],
    ]);
}

if ($clientId === '' || $clientSecret === '') {
    pantalla_aviso([
        'titulo'   => 'Google Drive no está configurado',
        'detalle'  => 'La empresa todavía no tiene credenciales de Google. Las carga quien administra '
                    . 'el inventario, en Configuración → Integración.',
        'tono'     => 'info',
        'acciones' => [['texto' => 'Volver', 'href' => $volver, 'icono' => 'arrow-left']],
    ]);
}

$rand = bin2hex(random_bytes(16));
$_SESSION['tenant_drive_state_rand'] = $rand;
$_SESSION['drive_volver']            = $volver;

$g = new Google_Client();
$g->setClientId($clientId);
$g->setClientSecret($clientSecret);
$g->setRedirectUri($redirectUri);
$g->addScope(['email', 'profile', Google_Service_Drive::DRIVE, Google_Service_Drive::DRIVE_FILE]);
$g->setAccessType('offline');
$g->setPrompt('consent');
$g->setLoginHint($email);
// State: td:{random32}:{company_id}
$g->setState('td:' . $rand . ':' . $cid);

app_session_cerrar_y_redirigir($g->createAuthUrl());

/** La vuelta de Google de siempre (la misma que usa login.php). */
function ithub_drive_callback_por_omision(): string {
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return 'https://' . $host . '/auth/superadmin_callback.php';
}
