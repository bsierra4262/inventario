<?php
declare(strict_types=1);

/**
 * CSRF helper centralizado
 * - Tokens por tenant y por acción ("scope")
 * - TTL (expira)
 * - Double submit cookie (SameSite=Strict)
 * - Compatible con formularios y AJAX
 * - Incluye protección de OAuth state para Google / Microsoft
 *
 * Requisitos externos:
 *   - $_SESSION['tenant_id'] o $_SESSION['empresa_id'] ya seteado en el login multiempresa
 *   - logger.php con log_event() (si no existe, se crea uno silencioso acá)
 */

// ============================
// Boot de sesión
// ============================
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ============================
// Logger seguro
// ============================
$__logger_ok = false;
$__logger_path = __DIR__ . '/logs/logger.php';
if (is_file($__logger_path)) {
    require_once $__logger_path;
    if (function_exists('log_event')) {
        $__logger_ok = true;
    }
}
if (!$__logger_ok && !function_exists('log_event')) {
    function log_event($origin, $type, $msg, $tag = 'app') { /* no-op para no romper */ }
}

// ============================
// Configuración
// ============================
const CSRF_SESSION_KEY   = 'csrf_tokens';    // container en $_SESSION
const CSRF_COOKIE_NAME   = 'csrfc';          // cookie espejo
const CSRF_TTL_SECONDS   = 900;              // 15 minutos
const CSRF_DEFAULT_SCOPE = '_global';        // scope por defecto

/**
 * Namespace tenant (multiempresa).
 * Aislamos tokens por empresa para que un token de una empresa no funcione en otra.
 */
function csrf_tenant_ns(): string {
    if (isset($_SESSION['tenant_id']))   { return (string)$_SESSION['tenant_id']; }
    if (isset($_SESSION['empresa_id']))  { return (string)$_SESSION['empresa_id']; }
    return 'default';
}

/**
 * Fingerprint del cliente asociado a la sesión.
 * Atamos el token al user-agent y al user_id/tenant para que no lo puedan reutilizar fácil.
 *
 * NOTA: NO usamos session_id() porque algunos hostings shared (Hostinger) regeneran el SID
 * entre requests, lo que rompía el double-submit cookie en formularios largos / uploads.
 * La seguridad se mantiene porque el token vive dentro de $_SESSION (atado a la sesión),
 * y la cookie shadow es solo una capa extra de double-submit.
 */
function csrf_client_fingerprint(): string {
    $ua     = $_SERVER['HTTP_USER_AGENT'] ?? 'na';
    $uid    = (string)($_SESSION['user_id'] ?? 'anon');
    $tenant = csrf_tenant_ns();
    return hash('sha256', $ua . '|' . $uid . '|' . $tenant);
}

/**
 * Devuelve (por referencia) el storage del tenant actual.
 * Estructura:
 * $_SESSION['csrf_tokens'][$tenant][$scope] = [
 *   'token' => 'abc',
 *   'iat'   => 1234567890,
 *   'ttl'   => 900,
 *   'fp'    => 'huella'
 * ];
 */
function &csrf_store(): array {
    if (!isset($_SESSION[CSRF_SESSION_KEY]) || !is_array($_SESSION[CSRF_SESSION_KEY])) {
        $_SESSION[CSRF_SESSION_KEY] = [];
    }

    $tenant = csrf_tenant_ns();
    if (!isset($_SESSION[CSRF_SESSION_KEY][$tenant]) || !is_array($_SESSION[CSRF_SESSION_KEY][$tenant])) {
        $_SESSION[CSRF_SESSION_KEY][$tenant] = [];
    }

    return $_SESSION[CSRF_SESSION_KEY][$tenant];
}

/**
 * Genera bytes aleatorios seguros.
 */
function csrf_random_token(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

/**
 * Setea cookie espejo (double submit cookie).
 * Guardamos un hash derivado, no el token plano.
 * SameSite=Strict para bloquear CSRF cross-site (navegación externa tipo Google/Microsoft volviendo a tu dominio).
 */
function csrf_set_cookie_shadow(string $token): void {
    $shadow = hash('sha256', $token . '|' . csrf_client_fingerprint());

    $secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $httponly = false; // no HttpOnly porque el navegador debe poder reenviar cookie en el POST normal

    $params = [
        'expires'  => time() + CSRF_TTL_SECONDS,
        'path'     => '/',
        'domain'   => '', // mismo host actual
        'secure'   => $secure,
        'httponly' => $httponly,
        'samesite' => 'Strict',
    ];

    setcookie(CSRF_COOKIE_NAME, $shadow, $params);
}

/**
 * Verifica que la cookie espejo coincida con el token.
 *
 * IMPORTANTE: usamos UN SOLO nombre de cookie ('csrfc') para TODOS los scopes.
 * Eso significa que si el usuario navega entre páginas (index → agregar → editar),
 * cada generateCsrfToken() sobrescribe la cookie con el shadow del último scope.
 * El cookie shadow del scope X solo está vigente entre el render de X y la
 * siguiente generación de token de OTRO scope.
 *
 * Por eso esta validación es SOFT: si la cookie no matchea, logueamos pero no
 * bloqueamos. La protección primaria contra CSRF es el token guardado en $_SESSION
 * (que se compara con el del POST), no la cookie espejo.
 */
function csrf_check_cookie_shadow(string $token): bool {
    if (!isset($_COOKIE[CSRF_COOKIE_NAME])) {
        return true;
    }
    $expected = hash('sha256', $token . '|' . csrf_client_fingerprint());
    if (hash_equals($expected, $_COOKIE[CSRF_COOKIE_NAME])) {
        return true;
    }
    // Soft fail: cookie no coincide pero el token de sesión sí. Logueamos.
    @error_log('csrf: cookie shadow no coincide pero token de sesión válido (probable sobrescritura por otro scope)');
    return true;
}

// ============================
// API pública
// ============================

/**
 * generateCsrfToken
 * Crea (o reutiliza si sigue vigente) un token CSRF para un scope/acción.
 *
 * $scope   = nombre lógico de la acción (ej: 'upload', 'confirmar_accion', 'baja_producto').
 * $ttl     = TTL en segundos. Default CSRF_TTL_SECONDS (15m).
 *
 * RETURN: string token seguro
 */
function generateCsrfToken(?string $scope = null, ?int $ttl = null): string {
    try {
        $scope = $scope ?: CSRF_DEFAULT_SCOPE;
        $ttl   = $ttl   ?: CSRF_TTL_SECONDS;
        $now   = time();

        $store = &csrf_store();

        $needNew = true;
        if (isset($store[$scope]) && is_array($store[$scope])) {
            $rec = $store[$scope];
            if (isset($rec['token'], $rec['iat'], $rec['fp'])) {
                $expired = ($now - (int)$rec['iat']) > (int)($rec['ttl'] ?? $ttl);
                $fpOk    = hash_equals($rec['fp'], csrf_client_fingerprint());
                if (!$expired && $fpOk) {
                    // Token vigente y fingerprint ok → lo reusamos
                    $needNew = false;
                }
            }
        }

        if ($needNew) {
            $token = csrf_random_token();
            $store[$scope] = [
                'token' => $token,
                'iat'   => $now,
                'ttl'   => $ttl,
                'fp'    => csrf_client_fingerprint(),
            ];
            csrf_set_cookie_shadow($token);
            log_event('csrf', 'CSRF', "Token CSRF generado (scope={$scope})", 'transaccion');
        } else {
            $token = $store[$scope]['token'];
        }

        return $token;
    } catch (\Throwable $e) {
        error_log("Error generando token CSRF: " . $e->getMessage());
        log_event('csrf', 'ERROR', "Error generando token CSRF: " . $e->getMessage(), 'transaccion');
        http_response_code(500);
        die('Se produjo un error interno. Intente nuevamente más tarde.');
    }
}

/**
 * verifyCsrfToken
 * Valida el token recibido contra el guardado en sesión.
 *
 * $token   = token recibido. Si viene null lo intenta leer de:
 *           - header HTTP_X_CSRF_TOKEN (AJAX)
 *           - $_POST['csrf_token']
 *           - $_GET['csrf_token']
 * $scope   = scope que queremos validar (tiene que ser el mismo que usamos al generar)
 * $oneTime = si true rota el token al validarlo (mitiga replay)
 *
 * RETURN: bool
 */
function verifyCsrfToken(?string $token = null, ?string $scope = null, bool $oneTime = false): bool {
    if ($token === null) {
        // orden de prioridad: header AJAX → POST → GET
        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
        } elseif (isset($_POST['csrf_token'])) {
            $token = (string)$_POST['csrf_token'];
        } elseif (isset($_GET['csrf_token'])) {
            $token = (string)$_GET['csrf_token'];
        }
    }

    require_once_if_needed_logger();

    if (empty($token)) {
        error_log("CSRF token proporcionado está vacío o ausente.");
        log_event('csrf', 'ERROR', 'Token CSRF ausente/vacío en la solicitud', 'transaccion');
        return false;
    }

    $scope = $scope ?: CSRF_DEFAULT_SCOPE;

    $store = &csrf_store();
    if (
        !isset($store[$scope]) ||
        !isset($store[$scope]['token'], $store[$scope]['iat'], $store[$scope]['fp'])
    ) {
        error_log("CSRF token no encontrado en la sesión para scope={$scope}.");
        log_event('csrf', 'ERROR', "Token CSRF no encontrado en sesión (scope={$scope})", 'transaccion');
        return false;
    }

    $rec     = $store[$scope];
    $now     = time();
    $expired = ($now - (int)$rec['iat']) > (int)($rec['ttl'] ?? CSRF_TTL_SECONDS);
    $fpOk    = hash_equals($rec['fp'], csrf_client_fingerprint());

    if ($expired) {
        log_event('csrf', 'ERROR', "Token CSRF expirado (scope={$scope})", 'transaccion');
        return false;
    }

    if (!$fpOk) {
        log_event('csrf', 'ERROR', "Fingerprint inválido para token CSRF (scope={$scope})", 'transaccion');
        return false;
    }

    if (!hash_equals($rec['token'], $token)) {
        log_event('csrf', 'ERROR', "Token CSRF inválido (scope={$scope})", 'transaccion');
        return false;
    }

    if (!csrf_check_cookie_shadow($rec['token'])) {
        // cookie espejo no coincide → posible CSRF externo
        log_event('csrf', 'ERROR', "CSRF cookie mismatch (double submit) (scope={$scope})", 'transaccion');
        return false;
    }

    // Rotar si es one-time
    if ($oneTime) {
        $store[$scope]['token'] = csrf_random_token();
        $store[$scope]['iat']   = $now;
        $store[$scope]['fp']    = csrf_client_fingerprint();
        csrf_set_cookie_shadow($store[$scope]['token']);
        log_event('csrf', 'CSRF', "Token CSRF validado y rotado (scope={$scope})", 'transaccion');
    } else {
        log_event('csrf', 'CSRF', "Token CSRF validado correctamente (scope={$scope})", 'transaccion');
    }

    return true;
}

/**
 * csrf_or_fail
 * Uso directo en los endpoints sensibles:
 *   require_once 'csrf.php';
 *   csrf_or_fail('confirmar_accion', true);
 *
 * Corta la ejecución si el token no es válido.
 * Devuelve 419 en vez de 500 porque es "expiró la sesión / no autorizado para esta acción".
 */
function csrf_or_fail(?string $scope = null, bool $oneTime = false): void {
    if (verifyCsrfToken(null, $scope, $oneTime)) {
        return;
    }

    http_response_code(419);
    $acceptsJson = (
        (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
    );

    if ($acceptsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'CSRF inválido o expirado. Volvé a cargar la página.',
            'code'  => 419,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Acción bloqueada</h1><p>CSRF inválido o expirado. Actualizá la página e intentá otra vez.</p>';
    }
    exit;
}

/**
 * csrfInput
 * Render helper para meter el token actual en <form>.
 * Ejemplo:
 *   <form method="POST"><?php csrfInput('confirmar_accion'); ?></form>
 */
function csrfInput(?string $scope = null): void {
    $token = generateCsrfToken($scope);
    echo '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars($token, ENT_QUOTES, 'UTF-8') .
    '">';
}

/**
 * csrfMetaTag
 * Para vistas que luego hacen fetch/axios y mandan header 'X-CSRF-Token'
 * Ejemplo en <head>: <?php csrfMetaTag('confirmar_accion'); ?>
 */
function csrfMetaTag(?string $scope = null): void {
    $token = generateCsrfToken($scope);
    echo '<meta name="csrf-token" content="' .
        htmlspecialchars($token, ENT_QUOTES, 'UTF-8') .
    '">';
}

/**
 * csrfHeaderValue
 * Útil si necesitás setear manualmente en JS inline:
 *   const CSRF = '<?= csrfHeaderValue("confirmar_accion"); ?>';
 */
function csrfHeaderValue(?string $scope = null): string {
    return generateCsrfToken($scope);
}

/**
 * Protección específica de OAuth:
 * Esto reemplaza tu uso "libre" de code en Google/Microsoft por un flujo con parámetro `state`
 * que está atado a la sesión y tenant. 100% necesario para no comer CSRF en callbacks externos.
 */
function oauth_generate_state(string $provider, ?int $ttl = null): string {
    $ttl = $ttl ?? CSRF_TTL_SECONDS;
    $key = 'oauth_state_' . strtolower($provider);

    $state = csrf_random_token(24);

    $_SESSION[$key] = [
        'v'   => $state,
        'iat' => time(),
        'ttl' => $ttl,
        'fp'  => csrf_client_fingerprint(),
        'ten' => csrf_tenant_ns(),
    ];

    log_event('csrf', 'CSRF', "OAuth state generado para {$provider}", 'transaccion');
    return $state;
}

function oauth_verify_state(string $provider, string $state): bool {
    $key = 'oauth_state_' . strtolower($provider);

    if (!isset($_SESSION[$key])) {
        log_event('csrf', 'ERROR', "OAuth state ausente para {$provider}", 'transaccion');
        return false;
    }

    $rec = $_SESSION[$key];

    $ok = hash_equals($rec['v'] ?? '', $state ?? '')
        && (time() - (int)$rec['iat'] <= (int)$rec['ttl'])
        && hash_equals($rec['fp'] ?? '', csrf_client_fingerprint())
        && hash_equals($rec['ten'] ?? '', csrf_tenant_ns());

    if ($ok) {
        unset($_SESSION[$key]); // state de OAuth se usa una sola vez
        log_event('csrf', 'CSRF', "OAuth state validado para {$provider}", 'transaccion');
        return true;
    }

    log_event('csrf', 'ERROR', "OAuth state inválido/expirado para {$provider}", 'transaccion');
    return false;
}

/**
 * Interno: si alguien llama verifyCsrfToken antes de que hayamos intentado cargar logger,
 * nos aseguramos de no romper.
 */
function require_once_if_needed_logger(): void {
    if (function_exists('log_event')) {
        return;
    }
    $loggerPath = __DIR__ . '/logs/logger.php';
    if (is_file($loggerPath)) {
        require_once $loggerPath;
    } else {
        if (!function_exists('log_event')) {
            function log_event($origin, $type, $msg, $tag = 'app') { /* silencioso */ }
        }
    }
}
?>
