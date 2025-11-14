<?php
/**
 * Configuración de plataforma (Hostinger)
 */

define('PLATFORM_ENV', 'prod'); // prod|dev

// === MASTER DB (plataforma) ===
define('MASTER_DB_HOST', 'localhost');
define('MASTER_DB_NAME', 'u566451014_master');
define('MASTER_DB_USER', 'u566451014_admin');
define('MASTER_DB_PASS', '***SECRETO-PURGADO***');

// === Paths seguros (dentro de public_html) ===
define('SECURE_BASE', __DIR__ . '/../../secure');                  // /public_html/secure
define('TENANTS_SECURE_PATH', SECURE_BASE . '/tenants');           // /public_html/secure/tenants
define('LOG_PATH', __DIR__ . '/../../logs/audit.log');

// === Proveedores soportados ===
const PROVIDERS = ['google', 'microsoft'];

// === Modo de alta de empresa ===
define('DEFAULT_TENANT_MODE', 'prefix');

// === Prefijo de tablas tenant cuando se use 'prefix' ===
define('TENANT_PREFIX_BASE', 't');

// === Seguridad ===
define('CSRF_TOKEN_NAME', '_csrf');
define('SESSION_SUPERADMIN_FLAG', 'is_superadmin');

// === Utilidades ===
function platform_log($msg){
    $line = '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL;
    @file_put_contents(LOG_PATH, $line, FILE_APPEND);
}

function ensure_dir($path){
    if(!is_dir($path)){
        mkdir($path, 0700, true);
    }
}
