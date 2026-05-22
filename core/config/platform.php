<?php
/**
 * Configuración de plataforma (Hostinger)
 * Todas las constantes usan defined() guard para ser seguras ante doble inclusión
 * (ocurre en subdominios virtuales donde realpath() resuelve distinto según el path de acceso).
 */

defined('PLATFORM_ENV')          || define('PLATFORM_ENV',          'prod');

// === MASTER DB (plataforma) ===
defined('MASTER_DB_HOST')        || define('MASTER_DB_HOST',        'localhost');
defined('MASTER_DB_NAME')        || define('MASTER_DB_NAME',        'u566451014_master');
defined('MASTER_DB_USER')        || define('MASTER_DB_USER',        'u566451014_admin');
defined('MASTER_DB_PASS')        || define('MASTER_DB_PASS',        '***SECRETO-PURGADO***');

// === Paths seguros (dentro de public_html) ===
defined('SECURE_BASE')           || define('SECURE_BASE',           __DIR__ . '/../../secure');
defined('TENANTS_SECURE_PATH')   || define('TENANTS_SECURE_PATH',   SECURE_BASE . '/tenants');
defined('LOG_PATH')              || define('LOG_PATH',              __DIR__ . '/../../logs/audit.log');

// === Proveedores soportados ===
defined('PROVIDERS')             || define('PROVIDERS',             ['google', 'microsoft']);

// === Modo de alta de empresa ===
defined('DEFAULT_TENANT_MODE')   || define('DEFAULT_TENANT_MODE',   'prefix');

// === Prefijo de tablas tenant cuando se use 'prefix' ===
defined('TENANT_PREFIX_BASE')    || define('TENANT_PREFIX_BASE',    't');

// === Seguridad ===
defined('CSRF_TOKEN_NAME')       || define('CSRF_TOKEN_NAME',       '_csrf');
defined('SESSION_SUPERADMIN_FLAG') || define('SESSION_SUPERADMIN_FLAG', 'is_superadmin');

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
