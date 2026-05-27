<?php
/**
 * Overrides de permisos a NIVEL PLATAFORMA (users_platform / superadmin).
 *
 * Tabla en la DB master: platform_role_permissions (role, action)
 * Solo aplica a los 4 roles platform: superadmin, admin, support, viewer.
 *
 * Logica:
 *   - Si hay rows para el rol → REEMPLAZA los defaults
 *   - Si no hay rows → usa la matriz default de session_role.php
 *
 * NOTA: 'superadmin' siempre tiene TODO (no se le puede quitar permisos
 * desde el editor; se protege por logica de UI).
 */

declare(strict_types=1);

require_once __DIR__ . '/session_role.php';

if (!defined('PLATFORM_ROLES')) {
    define('PLATFORM_ROLES', ['superadmin', 'admin', 'support', 'viewer']);
}
if (!defined('PLATFORM_ROLE_LABELS')) {
    define('PLATFORM_ROLE_LABELS', [
        'superadmin' => 'Super Administrador',
        'admin'      => 'Administrador',
        'support'    => 'Soporte',
        'viewer'     => 'Solo lectura',
    ]);
}

if (!function_exists('platform_perms_ensure_table')) {
function platform_perms_ensure_table(mysqli $masterConn): void {
    static $checked = false;
    if ($checked) return;
    try {
        $masterConn->query("CREATE TABLE IF NOT EXISTS `platform_role_permissions` (
            role   VARCHAR(40) NOT NULL,
            action VARCHAR(80) NOT NULL,
            PRIMARY KEY (role, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $checked = true;
    } catch (Throwable) {}
}
}

if (!function_exists('platform_perms_effective')) {
function platform_perms_effective(mysqli $masterConn, string $role): array {
    platform_perms_ensure_table($masterConn);
    try {
        $stmt = $masterConn->prepare("SELECT action FROM `platform_role_permissions` WHERE role = ?");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $rs = $stmt->get_result();
        $rows = [];
        while ($r = $rs->fetch_assoc()) { $rows[] = (string)$r['action']; }
        $stmt->close();
        if (count($rows) > 0) return $rows;
    } catch (Throwable) {}

    // Fallback: matriz default
    $matrix = platform_permissions_matrix();
    $default = [];
    foreach ($matrix as $action => $roles) {
        if (in_array($role, $roles, true)) $default[] = $action;
    }
    return $default;
}
}

if (!function_exists('platform_perms_set')) {
function platform_perms_set(mysqli $masterConn, string $role, array $actions): bool {
    if (!in_array($role, PLATFORM_ROLES, true)) return false;
    if ($role === 'superadmin') return false; // protegido: superadmin siempre TODO
    platform_perms_ensure_table($masterConn);
    try {
        $masterConn->begin_transaction();
        $del = $masterConn->prepare("DELETE FROM `platform_role_permissions` WHERE role = ?");
        $del->bind_param('s', $role); $del->execute(); $del->close();

        if (count($actions) > 0) {
            $ins = $masterConn->prepare("INSERT INTO `platform_role_permissions` (role, action) VALUES (?, ?)");
            foreach ($actions as $a) {
                $a = (string)$a;
                $ins->bind_param('ss', $role, $a);
                $ins->execute();
            }
            $ins->close();
        }
        $masterConn->commit();
        return true;
    } catch (Throwable) {
        try { $masterConn->rollback(); } catch (Throwable) {}
        return false;
    }
}
}

if (!function_exists('platform_perms_reset')) {
function platform_perms_reset(mysqli $masterConn, string $role): bool {
    if (!in_array($role, PLATFORM_ROLES, true)) return false;
    platform_perms_ensure_table($masterConn);
    try {
        $stmt = $masterConn->prepare("DELETE FROM `platform_role_permissions` WHERE role = ?");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('platform_perms_has_overrides')) {
function platform_perms_has_overrides(mysqli $masterConn, string $role): bool {
    platform_perms_ensure_table($masterConn);
    try {
        $stmt = $masterConn->prepare("SELECT COUNT(*) c FROM `platform_role_permissions` WHERE role = ?");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return ((int)($row['c'] ?? 0)) > 0;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('platform_perms_grouped')) {
function platform_perms_grouped(): array {
    return [
        'Empresas' => [
            'company.view'   => 'Ver lista de empresas',
            'company.create' => 'Crear nuevas empresas',
            'company.edit'   => 'Editar datos de empresa',
            'company.delete' => 'Eliminar empresas',
        ],
        'Usuarios de plataforma' => [
            'platform_user.manage' => 'Gestionar usuarios superadmin',
        ],
        'Usuarios de empresas (tenant)' => [
            'tenant_user.view'   => 'Ver usuarios de empresas',
            'tenant_user.manage' => 'Gestionar usuarios de empresas',
        ],
        'Integraciones' => [
            'integrations.manage' => 'Gestionar integraciones globales',
        ],
        'Auditoría' => [
            'logs.view' => 'Ver logs del sistema',
        ],
    ];
}
}
