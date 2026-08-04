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
    // Si el esquema ya está migrado (bin/migrar.php), no hace falta el DDL:
    // esto corría un CREATE TABLE IF NOT EXISTS en cada request que tocara permisos.
    if (defined('APP_SCHEMA_OK')) return;
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

// ─────────────────────────────────────────────────────────────────────────────
// ROLES PERSONALIZADOS (custom roles)
// El admin puede crear roles propios (ej: 'auditor', 'tecnico_externo') con sus
// propios permisos. Se almacenan en tn('custom_roles') y conviven con los
// builtin (admin/manager/user/viewer) sin reemplazarlos.
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('custom_roles_ensure_table')) {
function custom_roles_ensure_table(mysqli $conn): void {
    if (defined('APP_SCHEMA_OK')) return;   // ver nota en role_perms_ensure_table()
    static $checked = [];
    $key = spl_object_id($conn);
    if (isset($checked[$key])) return;
    try {
        $table = tn('custom_roles');
        $conn->query("CREATE TABLE IF NOT EXISTS `{$table}` (
            role_key VARCHAR(40) NOT NULL PRIMARY KEY,
            label    VARCHAR(120) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $checked[$key] = true;
    } catch (Throwable) {}
}
}

/**
 * Lista todos los custom roles del tenant: [role_key => label].
 */
if (!function_exists('custom_roles_all')) {
function custom_roles_all(mysqli $conn): array {
    custom_roles_ensure_table($conn);
    $out = [];
    try {
        $table = tn('custom_roles');
        $res = $conn->query("SELECT role_key, label FROM `{$table}` ORDER BY label ASC");
        if ($res) { while ($r = $res->fetch_assoc()) $out[(string)$r['role_key']] = (string)$r['label']; }
    } catch (Throwable) {}
    return $out;
}
}

if (!function_exists('custom_role_create')) {
function custom_role_create(mysqli $conn, string $key, string $label): bool {
    custom_roles_ensure_table($conn);
    // Sanitizar key: minusculas PRIMERO (sino las mayusculas se tratan como invalidas
    // y se reemplazan por _, lo que con trim al final puede comerse letras).
    $key = preg_replace('/[^a-z0-9_]/', '_', strtolower($key));
    $key = trim($key, '_');
    if ($key === '' || in_array($key, TENANT_ROLES, true)) return false;
    if (strlen($key) > 40 || $label === '') return false;
    try {
        $table = tn('custom_roles');
        $stmt = $conn->prepare("INSERT INTO `{$table}` (role_key, label) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE label = VALUES(label)");
        $stmt->bind_param('ss', $key, $label);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('custom_role_delete')) {
function custom_role_delete(mysqli $conn, string $key): bool {
    custom_roles_ensure_table($conn);
    if (in_array($key, TENANT_ROLES, true)) return false; // no se borran los builtin
    try {
        $tableR = tn('custom_roles');
        $tableP = tn('role_permissions');
        $conn->begin_transaction();
        $s1 = $conn->prepare("DELETE FROM `{$tableR}` WHERE role_key = ?");
        $s1->bind_param('s', $key); $s1->execute(); $s1->close();
        $s2 = $conn->prepare("DELETE FROM `{$tableP}` WHERE role = ?");
        $s2->bind_param('s', $key); $s2->execute(); $s2->close();
        $conn->commit();
        return true;
    } catch (Throwable) {
        try { $conn->rollback(); } catch (Throwable) {}
        return false;
    }
}
}

/**
 * Devuelve TODOS los roles disponibles para asignar a usuarios:
 * builtin + custom. Formato [role_key => label_legible].
 */
if (!function_exists('all_assignable_roles')) {
function all_assignable_roles(mysqli $conn): array {
    $out = [];
    foreach (TENANT_ROLES as $r) { $out[$r] = role_label($r); }
    foreach (custom_roles_all($conn) as $k => $lbl) { $out[$k] = $lbl; }
    return $out;
}
}

// Cuando llamamos role_perms_effective con un custom role que no tiene rows,
// retorna []. Hay que ajustar el flujo. Reescribimos para que custom roles sin
// rows tengan permisos vacios (no se aplica matriz default).
if (!function_exists('role_perms_effective_v2')) {
function role_perms_effective_v2(mysqli $conn, string $role): array {
    // Si es builtin, comportamiento original (DB con fallback a default)
    if (in_array($role, TENANT_ROLES, true)) {
        return role_perms_effective($conn, $role);
    }
    // Custom role: solo lo que esta en DB (sin fallback)
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
        return $rows;
    } catch (Throwable) { return []; }
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
            'asset.trash.view'  => 'Ver la papelera (archivados)',
            'asset.restore'     => 'Restaurar activos archivados',
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
        'Catálogos' => [
            'catalog.manage'    => 'Gestionar listas de "Asignado a" y "Ubicación"',
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
