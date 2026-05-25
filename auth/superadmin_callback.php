<?php
declare(strict_types=1);

/**
 * auth/superadmin_callback.php — OAuth callback unificado (Google + Microsoft)
 *
 * URI a registrar en Google Cloud Console / Azure:
 *   https://[tu-dominio]/auth/superadmin_callback.php
 *
 * Formatos del parámetro ?state=:
 *   g:{rand32}              → Superadmin con Google
 *   m:{rand32}              → Superadmin con Microsoft
 *   tg:{rand32}:{company}  → Tenant con Google
 *   tm:{rand32}:{company}  → Tenant con Microsoft
 */

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Ya autenticado como superadmin — SOLO si NO viene de un flujo tenant (tg: o tm:)
$_incomingState = $_GET['state'] ?? '';
$_isTenantState = str_starts_with($_incomingState, 'tg:') || str_starts_with($_incomingState, 'tm:');
if (!$_isTenantState
    && !empty($_SESSION[SESSION_SUPERADMIN_FLAG])
    && $_SESSION[SESSION_SUPERADMIN_FLAG] === true
) {
    header('Location: /dashboard/dashboard_resumen.php');
    exit;
}

$callbackUri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/superadmin_callback.php';

function sa_cb_error(string $msg): never {
    $_SESSION['sa_login_error'] = $msg;
    header('Location: /auth/superadmin_login.php');
    exit;
}

// ── Validar state ─────────────────────────────────────────────────────────────
$stateParam      = $_GET['state'] ?? '';
$tenantCompanyId = 0;
$isTenantFlow    = false;
$tenantProvider  = '';  // 'google' | 'microsoft' (solo para flujos tenant)

if (preg_match('/^tg:([a-f0-9]{32}):(\d+)$/', $stateParam, $sm)) {
    // Tenant — Google
    $providerCode    = 'g';
    $randPart        = $sm[1];
    $tenantCompanyId = (int)$sm[2];
    $isTenantFlow    = true;
    $tenantProvider  = 'google';
    $stateKey        = 'tenant_oauth_state_rand';

} elseif (preg_match('/^tm:([a-f0-9]{32}):(\d+)$/', $stateParam, $sm)) {
    // Tenant — Microsoft
    $providerCode    = 'm';
    $randPart        = $sm[1];
    $tenantCompanyId = (int)$sm[2];
    $isTenantFlow    = true;
    $tenantProvider  = 'microsoft';
    $stateKey        = 'tenant_ms_oauth_state_rand';

} elseif (preg_match('/^([gm]):([a-f0-9]{32})$/', $stateParam, $sm)) {
    // Superadmin
    $providerCode = $sm[1];
    $randPart     = $sm[2];
    $stateKey     = 'oauth_state_rand';

} else {
    sa_cb_error('Estado OAuth inválido. Intentá de nuevo.');
}

if (empty($_SESSION[$stateKey]) || !hash_equals($_SESSION[$stateKey], $randPart)) {
    sa_cb_error('Estado OAuth expirado o inválido. Intentá de nuevo.');
}
unset($_SESSION[$stateKey]);

$provider = $providerCode === 'g' ? 'google' : 'microsoft';

if (empty($_GET['code'])) {
    $desc = htmlspecialchars($_GET['error_description'] ?? $_GET['error'] ?? 'Sin código de autorización');
    if ($isTenantFlow) {
        header('Location: /auth/login.php?company=' . $tenantCompanyId . '&err=' . urlencode("Autenticación cancelada: {$desc}"));
        exit;
    }
    sa_cb_error("Autenticación cancelada o rechazada: {$desc}");
}

// ── Flujo tenant: empresa cliente (Google o Microsoft) ───────────────────────
if ($isTenantFlow) {
    $tenantCbError = function(string $msg) use ($tenantCompanyId): never {
        header('Location: /auth/login.php?company=' . $tenantCompanyId . '&err=' . urlencode($msg));
        exit;
    };

    if ($tenantCompanyId <= 0) {
        $tenantCbError('No hay empresa seleccionada. Intentá de nuevo.');
    }

    try {
        require_once __DIR__ . '/../core/tenants/install_tenant.php';

        ['conn' => $tconn, 'prefix' => $pf] = tenant_conn_by_company($tenantCompanyId);
        $ccTable    = $pf . 'cloud_config';
        $usersTable = $pf . 'users';

        // ── Tenant Google ─────────────────────────────────────────────
        if ($tenantProvider === 'google') {
            $tenantClientId = $tenantClientSecret = '';
            $res = $tconn->query("SELECT config_key, config_value FROM `$ccTable` WHERE provider = 'google'");
            if ($res) while ($row = $res->fetch_assoc()) {
                if ($row['config_key'] === 'client_id')     $tenantClientId     = (string)$row['config_value'];
                if ($row['config_key'] === 'client_secret') $tenantClientSecret = (string)$row['config_value'];
            }

            if ($tenantClientId === '' || $tenantClientSecret === '') {
                $tenantCbError('La empresa no tiene credenciales Google configuradas. Contactá al administrador.');
            }

            $gClient = new Google_Client();
            $gClient->setClientId($tenantClientId);
            $gClient->setClientSecret($tenantClientSecret);
            $gClient->setRedirectUri($callbackUri);

            $token = $gClient->fetchAccessTokenWithAuthCode($_GET['code']);
            if (isset($token['error'])) {
                throw new Exception($token['error_description'] ?? 'Google error: ' . $token['error']);
            }
            $gClient->setAccessToken($token);

            $oauth2   = new Google_Service_Oauth2($gClient);
            $userInfo = $oauth2->userinfo->get();

            $email = strtolower((string)($userInfo->email   ?? ''));
            $name  = (string)($userInfo->name    ?? '');
            $pic   = (string)($userInfo->picture ?? '');

            if ($email === '') { $tenantCbError('Google no devolvió un email válido.'); }

            // Limpiar sesión superadmin y establecer contexto tenant
            unset($_SESSION[SESSION_SUPERADMIN_FLAG]);
            session_regenerate_id(true);

            $_SESSION['company_id']           = $tenantCompanyId;
            $_SESSION['google_authenticated'] = true;
            $_SESSION['user_email']           = $email;
            $_SESSION['user_name']            = $name;
            $_SESSION['user_picture']         = $pic;
            $_SESSION['access_token']         = $token;

        // ── Tenant Microsoft ──────────────────────────────────────────
        } elseif ($tenantProvider === 'microsoft') {
            $tenantMsClientId     = '';
            $tenantMsClientSecret = '';
            $tenantMsTenantId     = 'common';
            $res = $tconn->query("SELECT config_key, config_value FROM `$ccTable` WHERE provider = 'microsoft'");
            if ($res) while ($row = $res->fetch_assoc()) {
                if ($row['config_key'] === 'client_id')     $tenantMsClientId     = (string)$row['config_value'];
                if ($row['config_key'] === 'client_secret') $tenantMsClientSecret = (string)$row['config_value'];
                if ($row['config_key'] === 'tenant_id')     $tenantMsTenantId     = (string)$row['config_value'];
            }

            if ($tenantMsClientId === '' || $tenantMsClientSecret === '') {
                $tenantCbError('La empresa no tiene credenciales Microsoft configuradas. Contactá al administrador.');
            }

            $http      = new GuzzleHttp\Client(['http_errors' => false, 'timeout' => 15]);
            $tokenRes  = $http->post("https://login.microsoftonline.com/{$tenantMsTenantId}/oauth2/v2.0/token", [
                'form_params' => [
                    'client_id'     => $tenantMsClientId,
                    'client_secret' => $tenantMsClientSecret,
                    'code'          => $_GET['code'],
                    'redirect_uri'  => $callbackUri,
                    'grant_type'    => 'authorization_code',
                    'scope'         => 'openid profile email User.Read',
                ]
            ]);
            $tokenData   = json_decode((string)$tokenRes->getBody(), true);
            if (isset($tokenData['error'])) {
                throw new Exception($tokenData['error_description'] ?? 'Microsoft OAuth error');
            }
            $accessToken = $tokenData['access_token'] ?? '';
            if ($accessToken === '') throw new Exception('No se recibió access token de Microsoft.');

            $meRes  = $http->get('https://graph.microsoft.com/v1.0/me', [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken]
            ]);
            $meData = json_decode((string)$meRes->getBody(), true);

            $email = strtolower((string)($meData['mail'] ?? $meData['userPrincipalName'] ?? ''));
            $name  = trim((string)($meData['displayName'] ?? ''));
            $pic   = '';

            if ($email === '') { $tenantCbError('Microsoft no devolvió un email válido.'); }

            // Limpiar sesión superadmin y establecer contexto tenant
            unset($_SESSION[SESSION_SUPERADMIN_FLAG]);
            session_regenerate_id(true);

            $_SESSION['company_id']              = $tenantCompanyId;
            $_SESSION['ms_authenticated']        = true;
            $_SESSION['user_email']              = $email;
            $_SESSION['user_name']               = $name;
            $_SESSION['user_picture']            = '';
            $_SESSION['ms_access_token']         = $accessToken;

        } else {
            $tenantCbError('Proveedor OAuth desconocido para este tenant.');
        }

        // ── Buscar o crear usuario en la DB del tenant ────────────────
        $stmt = $tconn->prepare("SELECT id FROM `$usersTable` WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->bind_result($uid);
        $found = $stmt->fetch();
        $stmt->close();

        if ($found) {
            $_SESSION['user_id'] = (int)$uid;
        } else {
            $username = explode('@', $email)[0];
            $randPass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
            $ins = $tconn->prepare("INSERT INTO `$usersTable` (username, password, email, role) VALUES (?, ?, ?, 'user')");
            $ins->bind_param('sss', $username, $randPass, $email);
            $ins->execute();
            $_SESSION['user_id'] = (int)$ins->insert_id;
            $ins->close();
        }

        header('Location: /inventario/index.php');
        exit;

    } catch (Throwable $te) {
        $tenantCbError('Error de autenticación: ' . $te->getMessage());
    }
}

// ── Procesar callback superadmin ──────────────────────────────────────────────
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

        $st = $db->conn->prepare("SELECT id, active, role FROM users_platform WHERE (google_sub = ? OR email = ?) LIMIT 1");
        $st->bind_param('ss', $sub, $email);
        $st->execute();
        $st->bind_result($uid, $active, $role);
        $found = $st->fetch();
        $st->close();

        if (!$found) { sa_cb_error("La cuenta {$email} no tiene acceso a este panel. Si deberías tener acceso, pedile al SuperAdmin que cree tu usuario con ese correo."); }
        if (!(int)$active) { sa_cb_error('Tu cuenta está desactivada. Contactá al SuperAdmin.'); }

        // Auto-vincular sub de Google si es la primera vez
        $st = $db->conn->prepare("UPDATE users_platform SET google_sub = ?, google_pic = ?, last_login = NOW() WHERE id = ?");
        $st->bind_param('ssi', $sub, $pic, $uid);
        $st->execute();
        $st->close();

        superadmin_login((int)$uid, $role);
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

        $st = $db->conn->prepare("SELECT id, active, role FROM users_platform WHERE (ms_sub = ? OR email = ?) LIMIT 1");
        $st->bind_param('ss', $sub, $email);
        $st->execute();
        $st->bind_result($uid, $active, $role);
        $found = $st->fetch();
        $st->close();

        if (!$found) { sa_cb_error("La cuenta {$email} no tiene acceso a este panel. Si deberías tener acceso, pedile al SuperAdmin que cree tu usuario con ese correo."); }
        if (!(int)$active) { sa_cb_error('Tu cuenta está desactivada. Contactá al SuperAdmin.'); }

        // Auto-vincular sub de Microsoft si es la primera vez
        $st = $db->conn->prepare("UPDATE users_platform SET ms_sub = ?, last_login = NOW() WHERE id = ?");
        $st->bind_param('si', $sub, $uid);
        $st->execute();
        $st->close();

        superadmin_login((int)$uid, $role);
        $_SESSION['superadmin_email'] = $email;
        $_SESSION['superadmin_name']  = $name ?: $email;

    } else {
        sa_cb_error('Proveedor OAuth desconocido.');
    }

    header('Location: /dashboard/dashboard_resumen.php');
    exit;

} catch (Throwable $e) {
    sa_cb_error('Error de autenticación: ' . $e->getMessage());
}
