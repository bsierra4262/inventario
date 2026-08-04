<?php
/**
 * Helpers para tenant_settings — tabla generica clave/valor por tenant.
 * Uso:
 *   tenant_setting_get($conn, 'disable_local_login', '0');
 *   tenant_setting_set($conn, 'disable_local_login', '1');
 *   tenant_settings_all($conn);
 *
 * La tabla se crea on-demand (lazy migration) la primera vez que se usa.
 */

declare(strict_types=1);

if (!function_exists('tenant_settings_ensure_table')) {
function tenant_settings_ensure_table(mysqli $conn): void {
    if (defined('APP_SCHEMA_OK')) return;   // el esquema ya está migrado
    static $checked = [];
    $key = spl_object_id($conn);
    if (isset($checked[$key])) return;
    try {
        $table = tn('tenant_settings');
        $conn->query("CREATE TABLE IF NOT EXISTS `{$table}` (
            setting_key   VARCHAR(80) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $checked[$key] = true;
    } catch (Throwable) { /* silencioso */ }
}
}

if (!function_exists('tenant_setting_get')) {
function tenant_setting_get(mysqli $conn, string $key, string $default = ''): string {
    tenant_settings_ensure_table($conn);
    try {
        $table = tn('tenant_settings');
        $stmt = $conn->prepare("SELECT setting_value FROM `{$table}` WHERE setting_key = ? LIMIT 1");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && $row['setting_value'] !== null) return (string)$row['setting_value'];
    } catch (Throwable) {}
    return $default;
}
}

if (!function_exists('tenant_setting_set')) {
function tenant_setting_set(mysqli $conn, string $key, string $value): bool {
    tenant_settings_ensure_table($conn);
    try {
        $table = tn('tenant_settings');
        $stmt = $conn->prepare("INSERT INTO `{$table}` (setting_key, setting_value) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param('ss', $key, $value);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('tenant_settings_all')) {
function tenant_settings_all(mysqli $conn): array {
    tenant_settings_ensure_table($conn);
    $out = [];
    try {
        $table = tn('tenant_settings');
        $res = $conn->query("SELECT setting_key, setting_value FROM `{$table}`");
        if ($res) { while ($r = $res->fetch_assoc()) { $out[(string)$r['setting_key']] = (string)$r['setting_value']; } }
    } catch (Throwable) {}
    return $out;
}
}

/**
 * Helper especifico: el cliente puede deshabilitar el login local (usuario+pass)
 * cuando tiene SSO configurado, forzando que TODOS los accesos sean por Google/Microsoft.
 */
if (!function_exists('tenant_local_login_disabled')) {
function tenant_local_login_disabled(mysqli $conn): bool {
    return tenant_setting_get($conn, 'disable_local_login', '0') === '1';
}
}
