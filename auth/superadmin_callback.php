<?php
declare(strict_types=1);

/**
 * auth/superadmin_callback.php — OAuth callback unificado (Google + Microsoft)
 *
 * URI a registrar en Google Cloud Console / Azure:
 *   https://[tu-dominio]/auth/superadmin_callback.php
 *
 * El parámetro ?state= codifica el proveedor: "g:{random}" = Google, "m:{random}" = Microsoft.
 */

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Ya autenticado
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

$callbackUri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/superadmin_callback.php';

function sa_cb_error(string $msg): never {
    $_SESSION['sa_login_error'] = $msg;
    header('Location: /auth/superadmin_login.php');
    exit;
}

// ── Validar state ─────────────────────────────────────────────────────────────
$stateParam = $_GET['state'] ?? '';
if (!preg_match('/^([gm]):([a-f0-9]{32})$/', $stateParam, $sm)) {
    sa_cb_error('Estado OAuth inválido. Intentá de nuevo.');
}
$providerCode = $sm[1];
$randPart     = $sm[2];

if (empty($_SESSION['oauth_state_rand']) || !hash_equals($_SESSION['oauth_state_rand'], $randPart)) {
    sa_cb_error('Estado OAuth expirado o inválido. Intentá de nuevo.');
}
unset($_SESSION['oauth_state_rand']);

$provider = $providerCode === 'g' ? 'google' : 'microsoft';

if (empty($_GET['code'])) {
    $desc = htmlspecialchars($_GET['error_description'] ?? $_GET['error'] ?? 'Sin código de autorización');
    sa_cb_error("Autenticación cancelada o rechazada: {$desc}");
}

// ── Procesar callback ─────────────────────────────────────────────────────────
try {
    $db = new MasterDB();

    // Migraciones columnas SSO extendidas
    foreach ([
        "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `ms_sub` VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE `users_platform` ADD COLUMN IF NOT EXISTS `ms_pic` VARCHAR(500) DEFAULT NULL",
    ] as $ddl) { try { $db->conn->query($ddl); } catch (Throwable) {} }

    // Cargar config SSO
    $config = [];
    $res = $db->conn->query("SELECT config_key, config_value FROM platform_config WHERE config_key LIKE 'sa_%'");
    if ($res) {
        while ($r = $res->fetch_assoc()) { $config[$r['config_key']] = $r['config_value']; }
    }

    // ── Google ────────────────────────────────────────────────────────────────
    if ($provider === 'google') {
        $clientId     = $config['sa_google_client_id']     ?? '';
        $clientSecret = $config['sa_google_client_secret'] ?? '';
        $hd           = strtolower(trim($config['sa_google_hd'] ?? ''));

        $client = new Google_Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($callbackUri);

        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        if (isset($token['error'])) {
            throw new Exception($token['error_description'] ?? 'Google OAuth error');
        }
        $client->setAccessToken($token);

        $oauth2 = new Google_Service_Oauth2($client);
        $info   = $oauth2->userinfo->get();

        $sub   = (string)($info->id      ?? '');
        $email = strtolower((string)($info->email   ?? ''));
        $name  = (string)($info->name    ?? '');
        $pic   = (string)($info->picture ?? '');

        if ($email === '') throw new Exception('Google no devolvió email.');

        if ($hd !== '' && !str_ends_with($email, '@' . $hd)) {
            sa_cb_error("Solo cuentas @{$hd} pueden acceder al panel SuperAdmin. Usaste: {$email}");
        }

        $st = $db->conn->prepare("SELECT id, active FROM users_platform WHERE (google_sub = ? OR email = ?) AND role = 'superadmin' LIMIT 1");
        $st->bind_param('ss', $sub, $email);
        $st->execute();
        $st->bind_result($uid, $active);
        $found = $st->fetch();
        $st->close();

        if (!$found) { sa_cb_error("Tu cuenta Google ({$email}) no está autorizada como superadmin."); }
        if (!(int)$active) { sa_cb_error('Tu cuenta de superadmin está desactivada.'); }

        $st = $db->conn->prepare("UPDATE users_platform SET google_sub = ?, google_pic = ?, last_login = NOW() WHERE id = ?");
        $st->bind_param('ssi', $sub, $pic, $uid);
        $st->execute();
        $st->close();

        superadmin_login((int)$uid);
        $_SESSION['superadmin_email'] = $email;
        $_SESSION['superadmin_name']  = $name ?: $email;
        $_SESSION['superadmin_pic']   = $pic;

    // ── Microsoft ─────────────────────────────────────────────────────────────
    } elseif ($provider === 'microsoft') {
        $clientId     = $config['sa_ms_client_id']     ?? '';
        $clientSecret = $config['sa_ms_client_secret'] ?? '';
        $tenantId     = $config['sa_ms_tenant_id']     ?? 'common';
        $hd           = strtolower(trim($config['sa_ms_hd'] ?? ''));

        $http = new GuzzleHttp\Client(['http_errors' => false, 'timeout' => 15]);

        $tokenRes  = $http->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'form_params' => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $_GET['code'],
                'redirect_uri'  => $callbackUri,
                'grant_type'    => 'authorization_code',
                'scope'         => 'openid profile email User.Read',
            ]
        ]);
        $tokenData = json_decode((string)$tokenRes->getBody(), true);
        if (isset($tokenData['error'])) {
            throw new Exception($tokenData['error_description'] ?? 'Microsoft OAuth error');
        }
        $accessToken = $tokenData['access_token'] ?? '';
        if ($accessToken === '') throw new Exception('No se recibió access token de Microsoft.');

        $meRes  = $http->get('https://graph.microsoft.com/v1.0/me', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken]
        ]);
        $meData = json_decode((string)$meRes->getBody(), true);

        $sub   = (string)($meData['id']                     ?? '');
        $email = strtolower((string)($meData['mail']        ?? $meData['userPrincipalName'] ?? ''));
        $name  = trim((string)($meData['displayName']       ?? ''));

        if ($email === '') throw new Exception('Microsoft no devolvió email.');

        if ($hd !== '' && !str_ends_with($email, '@' . $hd)) {
            sa_cb_error("Solo cuentas @{$hd} pueden acceder al panel SuperAdmin. Usaste: {$email}");
        }

        $st = $db->conn->prepare("SELECT id, active FROM users_platform WHERE (ms_sub = ? OR email = ?) AND role = 'superadmin' LIMIT 1");
        $st->bind_param('ss', $sub, $email);
        $st->execute();
        $st->bind_result($uid, $active);
        $found = $st->fetch();
        $st->close();

        if (!$found) { sa_cb_error("Tu cuenta Microsoft ({$email}) no está autorizada como superadmin."); }
        if (!(int)$active) { sa_cb_error('Tu cuenta de superadmin está desactivada.'); }

        $st = $db->conn->prepare("UPDATE users_platform SET ms_sub = ?, last_login = NOW() WHERE id = ?");
        $st->bind_param('si', $sub, $uid);
        $st->execute();
        $st->close();

        superadmin_login((int)$uid);
        $_SESSION['superadmin_email'] = $email;
        $_SESSION['superadmin_name']  = $name ?: $email;

    } else {
        sa_cb_error('Proveedor OAuth desconocido.');
    }

    header('Location: /dashboard/dashboard_superadmin.php');
    exit;

} catch (Throwable $e) {
    sa_cb_error('Error de autenticación: ' . $e->getMessage());
}
