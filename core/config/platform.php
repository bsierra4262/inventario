<?php
/**
 * Configuración de plataforma (Hostinger)
 * Todas las constantes usan defined() guard para ser seguras ante doble inclusión
 * (ocurre en subdominios virtuales donde realpath() resuelve distinto según el path de acceso).
 *
 * Los secretos NO viven acá. Se leen, en este orden:
 *   1. secure/config/secrets.php   (archivo real por entorno, nunca versionado)
 *   2. variables de entorno        (MASTER_DB_PASS, GOOGLE_CLIENT_ID, ...)
 * Plantilla en core/config/secrets.example.php.
 */

// ─────────────────────────────────────────────────────────────────────────────
// Carga de secretos
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('platform_secret')) {
    /**
     * Devuelve un secreto por clave. Cachea el archivo una sola vez por request.
     */
    function platform_secret(string $key, string $default = ''): string {
        static $secrets = null;
        if ($secrets === null) {
            $secrets = [];
            $file = __DIR__ . '/../../secure/config/secrets.php';
            if (is_file($file)) {
                $loaded = @include $file;
                if (is_array($loaded)) { $secrets = $loaded; }
            }
        }
        if (array_key_exists($key, $secrets) && $secrets[$key] !== '') {
            return (string)$secrets[$key];
        }
        $env = getenv($key);
        return ($env !== false && $env !== '') ? (string)$env : $default;
    }
}

defined('PLATFORM_ENV')          || define('PLATFORM_ENV',          'prod');

// === MASTER DB (plataforma) ===
defined('MASTER_DB_HOST')        || define('MASTER_DB_HOST',        platform_secret('MASTER_DB_HOST', 'localhost'));
defined('MASTER_DB_NAME')        || define('MASTER_DB_NAME',        platform_secret('MASTER_DB_NAME'));
defined('MASTER_DB_USER')        || define('MASTER_DB_USER',        platform_secret('MASTER_DB_USER'));
defined('MASTER_DB_PASS')        || define('MASTER_DB_PASS',        platform_secret('MASTER_DB_PASS'));

// === Paths seguros ===
defined('SECURE_BASE')           || define('SECURE_BASE',           __DIR__ . '/../../secure');
defined('TENANTS_SECURE_PATH')   || define('TENANTS_SECURE_PATH',   SECURE_BASE . '/tenants');
defined('LOG_PATH')              || define('LOG_PATH',              __DIR__ . '/../../logs/audit.log');

// === Proveedores soportados ===
defined('PROVIDERS')             || define('PROVIDERS',             ['google', 'microsoft']);

// === OAuth Google — credenciales de plataforma ===
// Si están vacías el sistema usa las credenciales por tenant (cloud_config).
defined('GOOGLE_CLIENT_ID')     || define('GOOGLE_CLIENT_ID',     platform_secret('GOOGLE_CLIENT_ID'));
defined('GOOGLE_CLIENT_SECRET') || define('GOOGLE_CLIENT_SECRET', platform_secret('GOOGLE_CLIENT_SECRET'));
// URI de callback registrada en Google Cloud Console — dejar vacío para auto-detectar
defined('GOOGLE_REDIRECT_URI')  || define('GOOGLE_REDIRECT_URI',  platform_secret('GOOGLE_REDIRECT_URI'));

// === OAuth Microsoft — credenciales de plataforma ===
defined('MS_CLIENT_ID')         || define('MS_CLIENT_ID',     platform_secret('MS_CLIENT_ID'));
defined('MS_CLIENT_SECRET')     || define('MS_CLIENT_SECRET', platform_secret('MS_CLIENT_SECRET'));
defined('MS_TENANT_ID')         || define('MS_TENANT_ID',     platform_secret('MS_TENANT_ID', 'common'));
defined('MS_REDIRECT_URI')      || define('MS_REDIRECT_URI',  platform_secret('MS_REDIRECT_URI'));

// === Modo de alta de empresa ===
defined('DEFAULT_TENANT_MODE')   || define('DEFAULT_TENANT_MODE',   'prefix');

// === Prefijo de tablas tenant cuando se use 'prefix' ===
defined('TENANT_PREFIX_BASE')    || define('TENANT_PREFIX_BASE',    't');

// === Seguridad ===
defined('SESSION_SUPERADMIN_FLAG') || define('SESSION_SUPERADMIN_FLAG', 'is_superadmin');

// === Logo de la plataforma ===
// Soporte automático: SVG tiene prioridad sobre PNG.
// Dropeá tu logo en assets/img/logo.svg (o logo.png) y aparece en todos lados.
defined('LOGO_FILE') || define('LOGO_FILE', __DIR__ . '/../../assets/img/logo.png');
defined('LOGO_URL')  || define('LOGO_URL',  '/assets/img/logo.png');
defined('LOGO_SVG_FILE') || define('LOGO_SVG_FILE', __DIR__ . '/../../assets/img/logo.svg');
defined('LOGO_SVG_URL')  || define('LOGO_SVG_URL',  '/assets/img/logo.svg');

/**
 * Guards function_exists para evitar "Cannot redeclare" si realpath() resuelve
 * el mismo archivo por dos paths distintos (ocurre en subdominios Hostinger).
 */
if (!function_exists('platform_logo_html')) {
    function platform_logo_html(): string {
        $style = 'width:100%;height:100%;object-fit:contain;display:block;border-radius:inherit';
        if (is_file(LOGO_SVG_FILE)) {
            return '<img src="' . LOGO_SVG_URL . '" alt="INTELLIHELP" style="' . $style . '" referrerpolicy="no-referrer">';
        }
        if (is_file(LOGO_FILE)) {
            return '<img src="' . LOGO_URL . '" alt="INTELLIHELP" style="' . $style . '" referrerpolicy="no-referrer">';
        }
        return 'i';
    }
}

if (!function_exists('platform_log')) {
    function platform_log($msg): void {
        $line = '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL;
        @file_put_contents(LOG_PATH, $line, FILE_APPEND);
    }
}

if (!function_exists('ensure_dir')) {
    function ensure_dir($path): void {
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
    }
}
