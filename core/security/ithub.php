<?php
/**
 * Ingreso con ITHub (sesión única de Intellihelp, OpenID Connect).
 *
 * El inventario es multiempresa y tiene clientes. Sólo DOS entradas pasan a
 * ITHub; el resto sigue exactamente como estaba:
 *
 *   - **La empresa ITH** (Intellihelp). Con `ITHUB_CLIENT_ID` y
 *     `ITHUB_CLIENT_SECRET` en secrets.php, `auth/login.php?company=ITH` (o
 *     elegir ITH en el selector) manda a ITHub en vez de mostrar Google,
 *     Microsoft o el formulario. ITHub ya validó la cuenta y el dominio, decide
 *     si la persona tiene el inventario y con qué rol (claim `rol`: un rol del
 *     tenant ITH, admin | manager | user | viewer | personalizado), y vuelve a
 *     `auth/ithub_callback.php`. App de ITHub: `inventario`.
 *   - **El panel SuperAdmin.** Con `ITHUB_SA_CLIENT_ID` y
 *     `ITHUB_SA_CLIENT_SECRET`, `auth/superadmin_login.php` manda a ITHub en
 *     vez del Google propio del panel y vuelve a
 *     `auth/superadmin_ithub_callback.php`. App de ITHub:
 *     `inventario-superadmin` (rol superadmin | admin | support | viewer).
 *
 * Las empresas clientes no se tocan: su login, su SSO propio y su formulario
 * siguen igual. Qué empresa va por ITHub lo dice `ITHUB_EMPRESA` (por omisión
 * `ITH`, comparado con `companies.subdomain`).
 *
 * Son dos clientes de OIDC distintos, cada uno con su secreto, su dirección de
 * vuelta y su aviso de cierre. Por eso todo recibe el `$cliente`
 * ('inventario' | 'superadmin').
 *
 * Salida: auth/logout.php y auth/superadmin_logout.php cierran la sesión local
 * y mandan a /oauth/logout de ITHub, que cierra la sesión única en todas las
 * apps. Al revés, cuando la sesión se cierra en ITHub (o le quitan la app a la
 * persona), ITHub avisa por detrás a auth/ithub_logout.php o
 * auth/superadmin_ithub_logout.php (back-channel logout): se anota el `sid` en
 * `ithub_sesiones_cerradas` (master, migración 2) y backend/db.php o
 * superadmin_require_login() cortan la sesión con ese sid en el pedido
 * siguiente.
 *
 * Google Drive sigue: los archivos de los activos de ITH viven ahí. Como
 * Google ya no es el login, el permiso de Drive se pide aparte, una vez, con
 * «Conectar Google Drive» (auth/drive_conectar.php), y tiene que ser la misma
 * cuenta de la sesión.
 *
 * Sin dependencias: el PEM de la clave pública se arma desde el JWK (n, e).
 * Contrato completo: md/INTEGRAR-APLICACION.md del repo ithub-portal.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/platform.php';

if (!defined('ITHUB_EVENTO_LOGOUT')) {
    define('ITHUB_EVENTO_LOGOUT', 'http://schemas.openid.net/event/backchannel-logout');
}
if (!defined('ITHUB_JWKS_TTL')) {
    define('ITHUB_JWKS_TTL', 3600);
}

if (!function_exists('ithub_cfg')) {
/**
 * Configuración de un cliente de ITHub, sacada de secrets.php.
 *
 * Los tests la fijan en `$GLOBALS['ITHUB_CFG_PRUEBA']` (mismo formato que
 * devuelve esta función, por cliente) para no depender del archivo real.
 *
 * @param string $cliente 'inventario' | 'superadmin'
 */
function ithub_cfg(string $cliente = 'inventario'): array {
    if (isset($GLOBALS['ITHUB_CFG_PRUEBA']) && is_array($GLOBALS['ITHUB_CFG_PRUEBA'])) {
        $c = (array)($GLOBALS['ITHUB_CFG_PRUEBA'][$cliente] ?? []);
    } else {
        $pre = $cliente === 'superadmin' ? 'ITHUB_SA_' : 'ITHUB_';
        $c = [
            'issuer'        => platform_secret('ITHUB_ISSUER'),
            'base_url'      => platform_secret('ITHUB_BASE_URL'),
            'empresa'       => platform_secret('ITHUB_EMPRESA'),
            'client_id'     => platform_secret($pre . 'CLIENT_ID'),
            'client_secret' => platform_secret($pre . 'CLIENT_SECRET'),
        ];
    }
    $c['issuer']        = rtrim((string)(($c['issuer'] ?? '') ?: 'https://ithub.intellihelp.tech'), '/');
    $c['base_url']      = rtrim((string)($c['base_url'] ?? ''), '/');
    $c['empresa']       = (string)(($c['empresa'] ?? '') ?: 'ITH');
    $c['client_id']     = (string)($c['client_id'] ?? '');
    $c['client_secret'] = (string)($c['client_secret'] ?? '');
    return $c;
}
}

if (!function_exists('ithub_habilitado')) {
/** ¿Ese cliente entra por ITHub? Hace falta client_id y client_secret. */
function ithub_habilitado(string $cliente = 'inventario'): bool {
    $c = ithub_cfg($cliente);
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}
}

if (!function_exists('ithub_empresa_es')) {
/**
 * ¿La empresa con ese subdominio entra por ITHub? Sólo ITH, y sólo con el
 * cliente `inventario` configurado. Las empresas clientes siempre dan false.
 */
function ithub_empresa_es(string $subdominio): bool {
    return ithub_habilitado('inventario')
        && $subdominio !== ''
        && strcasecmp($subdominio, ithub_cfg('inventario')['empresa']) === 0;
}
}

if (!function_exists('ithub_empresa_id')) {
/** Id (companies.id) de la empresa que entra por ITHub, o 0 si no está activa. */
function ithub_empresa_id(): int {
    require_once __DIR__ . '/../db/master_connection.php';
    $sub = ithub_cfg('inventario')['empresa'];
    $st = (new MasterDB())->pquery('SELECT id FROM companies WHERE subdomain = ? AND active = 1 LIMIT 1', 's', [$sub]);
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (int)($row['id'] ?? 0);
}
}

if (!function_exists('ithub_base_url')) {
/**
 * Origen público del inventario (https://desarrollo.intellihelp.tech). Sale de
 * `ITHUB_BASE_URL` si está; si no, del host del pedido, siempre en https.
 */
function ithub_base_url(string $cliente = 'inventario'): string {
    $b = ithub_cfg($cliente)['base_url'];
    if ($b !== '') { return $b; }
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return 'https://' . $host;
}
}

if (!function_exists('ithub_redirect_uri')) {
/** Dirección de vuelta registrada en ITHub para ese cliente (coincidencia exacta). */
function ithub_redirect_uri(string $cliente = 'inventario'): string {
    return ithub_base_url($cliente)
        . ($cliente === 'superadmin' ? '/auth/superadmin_ithub_callback.php' : '/auth/ithub_callback.php');
}
}

if (!function_exists('ithub_b64url')) {
/** Base64 URL sin relleno. */
function ithub_b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
}

if (!function_exists('ithub_b64url_dec')) {
function ithub_b64url_dec(string $txt): string {
    return (string)base64_decode(strtr($txt, '-_', '+/') . str_repeat('=', (4 - strlen($txt) % 4) % 4), true);
}
}

if (!function_exists('ithub_url_autorizacion')) {
/**
 * Arma la dirección de /oauth/authorize y deja en la sesión el state, el
 * nonce y el verificador de PKCE (S256), que valida el callback. Van con el
 * nombre del cliente para que un ingreso al panel y otro al inventario en
 * la misma sesión no se pisen.
 */
function ithub_url_autorizacion(string $cliente = 'inventario'): string {
    $c = ithub_cfg($cliente);
    $_SESSION['ithub_auth'][$cliente] = [
        'state' => bin2hex(random_bytes(16)),
        'nonce' => bin2hex(random_bytes(16)),
        'ver'   => ithub_b64url(random_bytes(48)),
    ];
    $a = $_SESSION['ithub_auth'][$cliente];
    return $c['issuer'] . '/oauth/authorize?' . http_build_query([
        'client_id'             => $c['client_id'],
        'redirect_uri'          => ithub_redirect_uri($cliente),
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $a['state'],
        'nonce'                 => $a['nonce'],
        'code_challenge'        => ithub_b64url(hash('sha256', $a['ver'], true)),
        'code_challenge_method' => 'S256',
    ]);
}
}

if (!function_exists('ithub_tomar_estado')) {
/**
 * Saca de la sesión el state, el nonce y el verificador de ese cliente (se
 * usan una sola vez) y dice si el state que volvió es el que se mandó.
 *
 * @return array{0: bool, 1: string, 2: string} [state válido, nonce, verificador]
 */
function ithub_tomar_estado(string $cliente, string $stateRecibido): array {
    $a = (array)($_SESSION['ithub_auth'][$cliente] ?? []);
    unset($_SESSION['ithub_auth'][$cliente]);
    $esperado = (string)($a['state'] ?? '');
    $ok = $esperado !== '' && $stateRecibido !== '' && hash_equals($esperado, $stateRecibido);
    return [$ok, (string)($a['nonce'] ?? ''), (string)($a['ver'] ?? '')];
}
}

if (!function_exists('ithub_http')) {
/**
 * Pedido HTTP a ITHub. Devuelve [código, cuerpo decodificado]. Sin conexión,
 * [0, []]. Con `$post` se autentica con el secreto del cliente; el secreto
 * nunca va a un log.
 */
function ithub_http(string $cliente, string $url, ?array $post = null, int $timeout = 10): array {
    $c = ithub_cfg($cliente);
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        $opts[CURLOPT_USERPWD] = rawurlencode($c['client_id']) . ':' . rawurlencode($c['client_secret']);
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$codigo, is_string($res) ? (json_decode($res, true) ?: []) : []];
}
}

if (!function_exists('ithub_canjear_codigo')) {
/** Canjea el código de autorización por los tokens (con el verificador de PKCE). */
function ithub_canjear_codigo(string $cliente, string $code, string $verificador): array {
    [, $r] = ithub_http($cliente, ithub_cfg($cliente)['issuer'] . '/oauth/token', [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => ithub_redirect_uri($cliente),
        'code_verifier' => $verificador,
    ]);
    return $r;
}
}

// ---- Claves públicas ----

if (!function_exists('ithub_der')) {
/** Elemento DER: etiqueta + largo (forma corta o larga) + valor. */
function ithub_der(int $tag, string $valor): string {
    $n = strlen($valor);
    if ($n < 0x80) {
        $largo = chr($n);
    } else {
        $b = ltrim(pack('N', $n), "\0");
        $largo = chr(0x80 | strlen($b)) . $b;
    }
    return chr($tag) . $largo . $valor;
}
}

if (!function_exists('ithub_der_entero')) {
/** Entero DER positivo (se antepone un 0 si el primer bit está prendido). */
function ithub_der_entero(string $bin): string {
    $bin = ltrim($bin, "\0");
    if ($bin === '' || ord($bin[0]) > 0x7f) {
        $bin = "\0" . $bin;
    }
    return ithub_der(0x02, $bin);
}
}

if (!function_exists('ithub_jwk_a_pem')) {
/** PEM de una clave pública RSA a partir del JWK (n, e en base64url). */
function ithub_jwk_a_pem(array $jwk): string {
    $rsa = ithub_der(0x30, ithub_der_entero(ithub_b64url_dec((string)($jwk['n'] ?? '')))
                         . ithub_der_entero(ithub_b64url_dec((string)($jwk['e'] ?? ''))));
    $alg = ithub_der(0x30, ithub_der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
    $spki = ithub_der(0x30, $alg . ithub_der(0x03, "\0" . $rsa));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
}

if (!function_exists('ithub_jwks')) {
/**
 * Claves de ITHub por kid, en caché una hora en secure/cache-ithub/ (fuera de
 * lo que se sirve por HTTP). Si el kid pedido no está (ITHub rotó la clave),
 * se vuelven a bajar una vez.
 *
 * @return array<string,array> kid → JWK
 */
function ithub_jwks(string $kid = ''): array {
    $dir = SECURE_BASE . '/cache-ithub';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $archivo = is_dir($dir) ? $dir . '/jwks.json' : null;

    if ($archivo !== null && is_file($archivo) && filemtime($archivo) > time() - ITHUB_JWKS_TTL) {
        $claves = json_decode((string)file_get_contents($archivo), true) ?: [];
        if ($claves !== [] && ($kid === '' || isset($claves[$kid]))) {
            return $claves;
        }
    }
    [$codigo, $r] = ithub_http('inventario', ithub_cfg('inventario')['issuer'] . '/oauth/jwks');
    $claves = [];
    foreach ((array)($codigo === 200 ? ($r['keys'] ?? []) : []) as $k) {
        if (is_array($k) && ($k['kty'] ?? '') === 'RSA' && isset($k['kid'], $k['n'], $k['e'])) {
            $claves[(string)$k['kid']] = $k;
        }
    }
    if ($claves !== [] && $archivo !== null) {
        @file_put_contents($archivo, json_encode($claves), LOCK_EX);
    }
    return $claves;
}
}

if (!function_exists('ithub_validar_jwt')) {
/**
 * Valida un JWT de ITHub (id_token o logout_token) para ese cliente y
 * devuelve sus claims.
 *
 * Comprueba: RS256, firma con la clave del kid, iss, aud = el client_id de
 * ESE cliente (un token del inventario no sirve para el panel ni al revés),
 * vencimiento (60 s de tolerancia) y, si se pasa, el nonce. Un logout_token
 * además tiene que traer el evento de back-channel logout y no puede traer
 * nonce.
 *
 * @param array<string,array>|null $claves para los tests; null = las de ITHub
 * @throws RuntimeException con un motivo corto (va al log, nunca a pantalla)
 */
function ithub_validar_jwt(string $jwt, string $cliente, ?string $nonce = null, bool $esLogout = false, ?array $claves = null): array {
    $partes = explode('.', $jwt);
    if (count($partes) !== 3) {
        throw new RuntimeException('formato');
    }
    [$h, $p, $s] = $partes;
    $cab = json_decode(ithub_b64url_dec($h), true);
    $c = json_decode(ithub_b64url_dec($p), true);
    if (!is_array($cab) || !is_array($c)) {
        throw new RuntimeException('formato');
    }
    if (($cab['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException('alg');
    }
    $kid = (string)($cab['kid'] ?? '');
    $claves ??= ithub_jwks($kid);
    if (!isset($claves[$kid])) {
        throw new RuntimeException('kid');
    }
    if (openssl_verify("$h.$p", ithub_b64url_dec($s), ithub_jwk_a_pem($claves[$kid]), OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('firma');
    }
    $cfg = ithub_cfg($cliente);
    $aud = $c['aud'] ?? '';
    $audOk = $cfg['client_id'] !== ''
        && (is_array($aud) ? in_array($cfg['client_id'], $aud, true) : $aud === $cfg['client_id']);
    if (($c['iss'] ?? '') !== $cfg['issuer'] || !$audOk) {
        throw new RuntimeException('iss/aud');
    }
    if ((int)($c['exp'] ?? 0) < time() - 60) {
        throw new RuntimeException('vencido');
    }
    if ($esLogout) {
        if (!isset($c['events'][ITHUB_EVENTO_LOGOUT]) || isset($c['nonce']) || (empty($c['sid']) && empty($c['sub']))) {
            throw new RuntimeException('logout_token');
        }
    } elseif ($nonce !== null && !hash_equals($nonce, (string)($c['nonce'] ?? ''))) {
        throw new RuntimeException('nonce');
    }
    return $c;
}
}

if (!function_exists('ithub_email_de')) {
/** El email del token, en minúsculas, o '' si no viene o no es un email. */
function ithub_email_de(array $claims): string {
    $email = strtolower(trim((string)($claims['email'] ?? '')));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}
}

if (!function_exists('ithub_usuario_nuevo')) {
/**
 * Nombre de usuario para alguien que entra por primera vez desde ITHub: la
 * parte local del email, y si ya está tomada, con un número al final.
 *
 * @param callable(string): bool $existe dice si un username ya está usado
 */
function ithub_usuario_nuevo(string $email, callable $existe): string {
    $base = preg_replace('/[^a-z0-9._\-]/i', '', strstr($email, '@', true) ?: $email) ?: 'usuario';
    $base = substr($base, 0, 100);
    $u = $base;
    for ($i = 2; $existe($u); $i++) { $u = $base . $i; }
    return $u;
}
}

// ---- Cierre por detrás (back-channel logout) ----

if (!function_exists('ithub_cerrar_sid')) {
/** Anota que la sesión de ITHub `sid` se cerró para ese cliente. */
function ithub_cerrar_sid(string $cliente, string $sid): void {
    require_once __DIR__ . '/../db/master_connection.php';
    $db = new MasterDB();
    $db->pquery('INSERT INTO `ithub_sesiones_cerradas` (cliente, sid, cerrada_en) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE cerrada_en = NOW()', 'ss', [$cliente, $sid])->close();
    // Limpieza: la sesión más larga del inventario es de 8 h, así que nada
    // más viejo que un par de días puede cortar a nadie.
    $db->conn->query('DELETE FROM `ithub_sesiones_cerradas` WHERE cerrada_en < NOW() - INTERVAL 2 DAY');
}
}

if (!function_exists('ithub_sid_cerrado')) {
/**
 * ¿La sesión de ITHub `sid` ya se cerró para ese cliente? Ante un error de
 * base contesta false: un fallo momentáneo no es un cierre.
 */
function ithub_sid_cerrado(string $cliente, string $sid): bool {
    try {
        require_once __DIR__ . '/../db/master_connection.php';
        $st = (new MasterDB())->pquery('SELECT 1 FROM `ithub_sesiones_cerradas` WHERE cliente = ? AND sid = ? LIMIT 1',
                                       'ss', [$cliente, $sid]);
        $hay = $st->get_result()->fetch_row() !== null;
        $st->close();
        return $hay;
    } catch (Throwable) {
        return false;
    }
}
}

if (!function_exists('ithub_atender_aviso_cierre')) {
/**
 * Atiende el POST de ITHub con el logout_token (lo llama el servidor de
 * ITHub, no un navegador: no hay cookie ni CSRF; lo que se valida es la
 * firma). 200 si se procesó, 400 si el token no es válido, como pide el
 * estándar.
 */
function ithub_atender_aviso_cierre(string $cliente): never {
    header('Cache-Control: no-store');
    if (is_file(__DIR__ . '/../../logs/logger.php')) { require_once __DIR__ . '/../../logs/logger.php'; }
    $log = static function (string $tipo, string $msg): void {
        if (function_exists('log_event')) { log_event('ithub', $tipo, $msg, 'auth'); }
    };
    if (!ithub_habilitado($cliente) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(400);
        exit;
    }
    try {
        $c = ithub_validar_jwt((string)($_POST['logout_token'] ?? ''), $cliente, null, true);
    } catch (Throwable $e) {
        $log('ITHUB_AVISO_INVALIDO', "{$cliente}: logout_token rechazado ({$e->getMessage()})");
        http_response_code(400);
        exit;
    }
    $sid = (string)($c['sid'] ?? '');
    if ($sid !== '') {
        try {
            ithub_cerrar_sid($cliente, $sid);
        } catch (Throwable $e) {
            $log('ITHUB_AVISO_ERROR', "{$cliente}: no se pudo anotar el cierre ({$e->getMessage()})");
            http_response_code(500);
            exit;
        }
    }
    // El logout_token no trae el email (sólo `sub`, el id de ITHub): la salida
    // de la persona queda anotada cuando su sesión cae en el pedido siguiente.
    $log('ITHUB_AVISO_CIERRE', "{$cliente}: ITHub avisó el cierre de una sesión única");
    http_response_code(200);
    exit;
}
}

if (!function_exists('ithub_url_salida')) {
/**
 * Dirección de salida de ITHub: cierra la sesión única y vuelve a
 * `$volverA` (tiene que estar registrada en ITHub tal cual). Con el id_token
 * como pista, ITHub no pregunta.
 */
function ithub_url_salida(string $cliente, ?string $idToken, string $volverA): string {
    $q = ['post_logout_redirect_uri' => $volverA, 'client_id' => ithub_cfg($cliente)['client_id']];
    if ($idToken) {
        $q['id_token_hint'] = $idToken;
    }
    return ithub_cfg($cliente)['issuer'] . '/oauth/logout?' . http_build_query($q);
}
}
