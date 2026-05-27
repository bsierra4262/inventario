<?php
/**
 * Permisos custom por tenant (override de la matriz default en permissions.php).
 *
 * Logica:
 *   - Si el tenant NO tiene overrides para un rol → usa la matriz default
 *   - Si tiene overrides → la lista en DB REEMPLAZA la default para ese rol
 *
 * Tabla creada on-demand: t_<x>_role_permissions (role, action)
 *
 * UI: inventario/roles.php (admin del tenant)
 */

declare(strict_types=1);

require_once __DIR__ . '/permissions.php';

if (!function_exists('role_perms_ensure_table')) {
function role_perms_ensure_table(mysqli $conn): void {
    static $checked = [];
    $key = spl_object_id($conn);
    if (isset($checked[$key])) return;
    try {
        $table = tn('role_permissions');
        $conn->query("CREATE TABLE IF NOT EXISTS `{$table}` (
            role   VARCHAR(40) NOT NULL,
            action VARCHAR(80) NOT NULL,
            PRIMARY KEY (role, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $checked[$key] = true;
    } catch (Throwable) {}
}
}

/**
 * Devuelve la lista efectiva de permisos para el rol.
 * - Si hay overrides en DB → devuelve esa lista
 * - Si no → devuelve la lista default de la matriz hardcoded
 *
 * Retorna ['action1', 'action2', ...]
 */
if (!function_exists('role_perms_effective')) {
function role_perms_effective(mysqli $conn, string $role): array {
    role_perms_ensure_table($conn);
    try {
        $table = tn('role_permissions');
        $stmt = $conn->prepare("SELECT action FROM `{$table}` WHERE role = ?");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $rs = $stmt->get_result();
        $rows = [];
        while ($r = $rs->fetch_assoc()) { $rows[] = (string)$r['action']; }
        $stmt->close();
        if (count($rows) > 0) return $rows;
    } catch (Throwable) {}

    // Fallback a matriz default
    $matrix = tenant_permissions_matrix();
    $default = [];
    foreach ($matrix as $action => $allowedRoles) {
        if (in_array($role, $allowedRoles, true)) {
            $default[] = $action;
        }
    }
    return $default;
}
}

/**
 * Sobreescribe la lista de permisos del rol con la lista dada.
 * Borra todas las rows existentes para ese role y las reemplaza por las nuevas.
 * Si $actions = [], queda con DENY total (no defaultea — para defaultear usar reset).
 *
 * Pasar 'admin' aqui es peligroso (se podria quedar sin admin que pueda restaurar).
 * El UI debe prevenir editar 'admin' o forzar siempre user.view + integration.edit minimo.
 */
if (!function_exists('role_perms_set')) {
function role_perms_set(mysqli $conn, string $role, array $actions): bool {
    role_perms_ensure_table($conn);
    try {
        $table = tn('role_permissions');
        $conn->begin_transaction();
        $del = $conn->prepare("DELETE FROM `{$table}` WHERE role = ?");
        $del->bind_param('s', $role);
        $del->execute();
        $del->close();

        if (count($actions) > 0) {
            $ins = $conn->prepare("INSERT INTO `{$table}` (role, action) VALUES (?, ?)");
            foreach ($actions as $a) {
                $a = (string)$a;
                $ins->bind_param('ss', $role, $a);
                $ins->execute();
            }
            $ins->close();
        }

        $conn->commit();
        return true;
    } catch (Throwable) {
        try { $conn->rollback(); } catch (Throwable) {}
        return false;
    }
}
}

/**
 * Restaura el rol a sus permisos default (borra todos los overrides).
 */
if (!function_exists('role_perms_reset')) {
function role_perms_reset(mysqli $conn, string $role): bool {
    role_perms_ensure_table($conn);
    try {
        $table = tn('role_permissions');
        $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE role = ?");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable) { return false; }
}
}

/**
 * Indica si el rol tiene overrides (true) o usa los defaults (false).
 */
if (!function_exists('role_perms_has_overrides')) {
function role_perms_has_overrides(mysqli $conn, string $role): bool {
    role_perms_ensure_table($conn);
    try {
        $table = tn('role_permissions');
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE role = ?");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return ((int)($row['c'] ?? 0)) > 0;
    } catch (Throwable) { return false; }
}
}

/**
 * Agrupa los permisos por modulo para mostrar en el UI.
 */
if (!function_exists('permissions_grouped')) {
function permissions_grouped(): array {
    $groups = [
        'Activos' => [
            'asset.view'        => 'Ver activos',
            'asset.create'      => 'Crear activos',
            'asset.edit'        => 'Editar activos',
            'asset.archive'     => 'Archivar / Dar de baja',
            'asset.delete'      => 'Eliminar definitivamente',
            'asset.export'      => 'Exportar CSV',
            'asset.import_csv'  => 'Importar CSV',
            'asset.backup_full' => 'Backup completo',
        ],
        'Reportes' => [
            'report.view'       => 'Ver reportes',
        ],
        'Usuarios' => [
            'user.view'         => 'Ver lista de usuarios',
            'user.create'       => 'Crear usuarios',
            'user.edit'         => 'Editar usuarios',
            'user.delete'       => 'Eliminar usuarios',
        ],
        'Integraciones' => [
            'integration.view'  => 'Ver configuración Drive/Cloud',
            'integration.edit'  => 'Editar configuración Drive/Cloud',
        ],
        'Cuenta propia' => [
            'self.change_password' => 'Cambiar su propia contraseña',
        ],
    ];
    return $groups;
}
}
