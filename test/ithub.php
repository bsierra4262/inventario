<?php
/**
 * Casos del ingreso con ITHub (core/security/ithub.php). Los incluye
 * run_tests.php dentro del grupo ITHUB y usan chequear().
 *
 * No llaman a ITHub ni necesitan base: se firma con una clave RSA generada en
 * el momento y las claves se le pasan a ithub_validar_jwt() directamente. La
 * configuración se fija en $GLOBALS['ITHUB_CFG_PRUEBA'] para no depender de
 * secure/config/secrets.php.
 */

declare(strict_types=1);

require_once RAIZ . '/core/security/ithub.php';

$GLOBALS['ITHUB_CFG_PRUEBA'] = [
    'inventario' => ['issuer' => 'https://ithub.intellihelp.tech', 'base_url' => 'https://desarrollo.intellihelp.tech',
                     'empresa' => 'ITH', 'client_id' => 'inventario', 'client_secret' => 'ith_prueba'],
    'superadmin' => ['issuer' => 'https://ithub.intellihelp.tech', 'base_url' => 'https://desarrollo.intellihelp.tech',
                     'client_id' => 'inventario-superadmin', 'client_secret' => 'ith_prueba_sa'],
];
chequear('Con client_id y secreto, ITH y el panel entran por ITHub',
    ithub_habilitado('inventario') && ithub_habilitado('superadmin'));
chequear('Sólo la empresa ITH va por ITHub (sin importar mayúsculas)',
    ithub_empresa_es('ITH') && ithub_empresa_es('ith'));
chequear('Las empresas clientes siguen con su login', !ithub_empresa_es('ACME') && !ithub_empresa_es(''));
chequear('Cada cliente vuelve a su propio callback',
    ithub_redirect_uri('inventario') === 'https://desarrollo.intellihelp.tech/auth/ithub_callback.php'
 && ithub_redirect_uri('superadmin') === 'https://desarrollo.intellihelp.tech/auth/superadmin_ithub_callback.php');

// ---- Autorización ----
$_SESSION = [];
$__url = ithub_url_autorizacion('inventario');
parse_str((string)parse_url($__url, PHP_URL_QUERY), $__q);
chequear('La autorización va a /oauth/authorize de ITHub con PKCE S256',
    str_starts_with($__url, 'https://ithub.intellihelp.tech/oauth/authorize?')
 && ($__q['code_challenge_method'] ?? '') === 'S256' && ($__q['client_id'] ?? '') === 'inventario');
$__ver = $_SESSION['ithub_auth']['inventario']['ver'] ?? '';
chequear('El code_challenge es el SHA-256 del verificador guardado en la sesión',
    $__ver !== '' && ($__q['code_challenge'] ?? '') === ithub_b64url(hash('sha256', $__ver, true)));
ithub_url_autorizacion('superadmin');
chequear('Un ingreso al panel no pisa el state del inventario',
    ($_SESSION['ithub_auth']['inventario']['state'] ?? '') === ($__q['state'] ?? 'x'));
[$__ok] = ithub_tomar_estado('inventario', 'otro-state');
chequear('Un state distinto se rechaza', $__ok === false);
[$__ok] = ithub_tomar_estado('inventario', (string)($__q['state'] ?? ''));
chequear('Y el state se usa una sola vez (ya se consumió)', $__ok === false);
$__url = ithub_url_autorizacion('inventario');
parse_str((string)parse_url($__url, PHP_URL_QUERY), $__q);
[$__ok, $__nonce] = ithub_tomar_estado('inventario', (string)$__q['state']);
chequear('El state correcto pasa y devuelve el nonce enviado', $__ok && $__nonce === ($__q['nonce'] ?? ''));

// ---- Firma y claims ----
if (!function_exists('openssl_pkey_new')) {
    chequear('openssl disponible para firmar los tokens de prueba', false, 'falta la extensión openssl');
} else {
    // En Windows openssl_pkey_new necesita el openssl.cnf que trae PHP.
    $__cnf = [];
    $__ruta = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
    if (is_file($__ruta)) { $__cnf['config'] = $__ruta; }
    $__clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $__cnf);
    $__det = openssl_pkey_get_details($__clave);
    $__jwk = ['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig',
              'n' => ithub_b64url($__det['rsa']['n']), 'e' => ithub_b64url($__det['rsa']['e'])];
    $__claves = ['k1' => $__jwk];

    chequear('El PEM armado desde el JWK es la misma clave pública',
        str_replace(["\r", "\n"], '', ithub_jwk_a_pem($__jwk)) === str_replace(["\r", "\n"], '', $__det['key']));

    $__firmar = static function (array $claims, string $kid = 'k1', string $alg = 'RS256') use ($__clave): string {
        $h = ithub_b64url(json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid]));
        $p = ithub_b64url(json_encode($claims));
        openssl_sign("$h.$p", $firma, $__clave, OPENSSL_ALGO_SHA256);
        return "$h.$p." . ithub_b64url($firma);
    };
    $__falla = static function (callable $fn): string {
        try { $fn(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); }
    };
    $__base = ['iss' => 'https://ithub.intellihelp.tech', 'aud' => 'inventario', 'sub' => '7',
               'sid' => str_repeat('a', 32), 'email' => 'LCampos@intellihelp.tech', 'rol' => 'manager',
               'nonce' => 'n1', 'iat' => time(), 'exp' => time() + 300];

    $__c = ithub_validar_jwt($__firmar($__base), 'inventario', 'n1', false, $__claves);
    chequear('Un id_token bien firmado pasa y trae el rol', ($__c['rol'] ?? '') === 'manager');
    chequear('El email se toma en minúsculas', ithub_email_de($__c) === 'lcampos@intellihelp.tech');

    $__p = explode('.', $__firmar($__base));
    $__p[1] = ithub_b64url(json_encode(['rol' => 'admin'] + $__base));
    chequear('Cambiar el contenido rompe la firma',
        $__falla(fn() => ithub_validar_jwt(implode('.', $__p), 'inventario', 'n1', false, $__claves)) === 'firma');
    chequear('Un token del inventario no sirve para el panel (aud)',
        $__falla(fn() => ithub_validar_jwt($__firmar($__base), 'superadmin', 'n1', false, $__claves)) === 'iss/aud');
    chequear('Ni uno del panel para el inventario',
        $__falla(fn() => ithub_validar_jwt($__firmar(['aud' => 'inventario-superadmin'] + $__base), 'inventario', 'n1', false, $__claves)) === 'iss/aud');
    chequear('Otro emisor (iss) no sirve',
        $__falla(fn() => ithub_validar_jwt($__firmar(['iss' => 'https://evil.tld'] + $__base), 'inventario', 'n1', false, $__claves)) === 'iss/aud');
    chequear('Vencido no sirve',
        $__falla(fn() => ithub_validar_jwt($__firmar(['exp' => time() - 120] + $__base), 'inventario', 'n1', false, $__claves)) === 'vencido');
    chequear('Con otro nonce no sirve (repetición)',
        $__falla(fn() => ithub_validar_jwt($__firmar($__base), 'inventario', 'otro', false, $__claves)) === 'nonce');
    chequear('Sólo RS256',
        $__falla(fn() => ithub_validar_jwt($__firmar($__base, 'k1', 'none'), 'inventario', 'n1', false, $__claves)) === 'alg');
    chequear('Un kid desconocido no sirve',
        $__falla(fn() => ithub_validar_jwt($__firmar($__base, 'k9'), 'inventario', 'n1', false, $__claves)) === 'kid');
    chequear('Basura no es un token',
        $__falla(fn() => ithub_validar_jwt('no.es-un.token.jwt', 'inventario', null, false, $__claves)) === 'formato');

    $__logout = ['iss' => 'https://ithub.intellihelp.tech', 'aud' => 'inventario-superadmin', 'sub' => '7',
                 'sid' => str_repeat('b', 32), 'iat' => time(), 'exp' => time() + 120, 'jti' => 'x',
                 'events' => [ITHUB_EVENTO_LOGOUT => new stdClass()]];
    chequear('El aviso de cierre válido trae el sid',
        (ithub_validar_jwt($__firmar($__logout), 'superadmin', null, true, $__claves)['sid'] ?? '') === str_repeat('b', 32));
    chequear('Un id_token no sirve como aviso de cierre',
        $__falla(fn() => ithub_validar_jwt($__firmar(['aud' => 'inventario-superadmin'] + $__base), 'superadmin', null, true, $__claves)) === 'logout_token');
    chequear('Un aviso de cierre con nonce se rechaza',
        $__falla(fn() => ithub_validar_jwt($__firmar(['nonce' => 'x'] + $__logout), 'superadmin', null, true, $__claves)) === 'logout_token');
}

// ---- Usuario nuevo ----
$__tomados = ['lcampos' => true, 'lcampos2' => true];
chequear('El usuario nuevo es la parte local del email, sin pisar uno existente',
    ithub_usuario_nuevo('lcampos@intellihelp.tech', fn(string $u) => isset($__tomados[$u])) === 'lcampos3'
 && ithub_usuario_nuevo('nbaldelli@intellihelp.tech', fn(string $u) => false) === 'nbaldelli');

// ---- Salida ----
$__s = ithub_url_salida('inventario', 'tok.en.x', 'https://desarrollo.intellihelp.tech/auth/login.php?company=ITH&bye=1');
chequear('La salida va a /oauth/logout de ITHub con el id_token como pista',
    str_starts_with($__s, 'https://ithub.intellihelp.tech/oauth/logout?') && str_contains($__s, 'id_token_hint=tok.en.x'));
chequear('Y vuelve al login de ITH',
    str_contains($__s, 'post_logout_redirect_uri=' . rawurlencode('https://desarrollo.intellihelp.tech/auth/login.php?company=ITH&bye=1')));

$GLOBALS['ITHUB_CFG_PRUEBA'] = ['inventario' => [], 'superadmin' => []];
chequear('Sin las claves de ITHub, ITH y el panel siguen con su login de siempre',
    !ithub_habilitado('inventario') && !ithub_habilitado('superadmin') && !ithub_empresa_es('ITH'));
unset($GLOBALS['ITHUB_CFG_PRUEBA']);
$_SESSION = [];
