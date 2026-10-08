<?php
require_once __DIR__ . '/../config/platform.php';

/**
 * Matriz de permisos para usuarios de la plataforma SuperAdmin.
 * Roles: superadmin, admin, support, viewer
 */
function platform_permissions_matrix(): array {
    return [
        // Empresas
        'company.view'          => ['superadmin', 'admin', 'support', 'viewer'],
        'company.create'        => ['superadmin', 'admin'],
        'company.edit'          => ['superadmin', 'admin'],
        'company.delete'        => ['superadmin'],
        // El respaldo incluye hashes de contraseña y el client_secret de OAuth
        // del tenant, así que no puede depender de `company.view` (que tiene
        // hasta el rol `viewer`): es una acción de superadmin.
        'company.backup'        => ['superadmin'],
        // Usuarios de plataforma
        'platform_user.manage'  => ['superadmin'],
        // Usuarios de empresas (tenant)
        'tenant_user.view'      => ['superadmin', 'admin', 'support'],
        'tenant_user.manage'    => ['superadmin', 'admin'],
        // Integraciones y config
        'integrations.manage'   => ['superadmin'],
        // Logs y auditoría
        'logs.view'             => ['superadmin', 'admin'],
    ];
}

function platform_can(string $action): bool {
    $role = $_SESSION['superadmin_role'] ?? 'viewer';

    // superadmin siempre tiene TODO (proteccion anti-lockout)
    if ($role === 'superadmin') return true;

    // Chequear overrides en DB master con cache por request
    static $cache = [];
    if (!isset($cache[$role])) {
        $cache[$role] = null;
        try {
            $pdbPath = __DIR__ . '/platform_permissions_db.php';
            if (is_file($pdbPath)) {
                require_once $pdbPath;
                require_once __DIR__ . '/../db/master_connection.php';
                $mdb = new MasterDB();
                $cache[$role] = platform_perms_effective($mdb->conn, $role);
            }
        } catch (Throwable) { $cache[$role] = null; }
    }
    if (is_array($cache[$role])) {
        return in_array($action, $cache[$role], true);
    }

    // Fallback: matriz default
    $matrix = platform_permissions_matrix();
    if (!isset($matrix[$action])) return false;
    return in_array($role, $matrix[$action], true);
}

/**
 * Que un fallo inesperado del panel se vea como el panel.
 *
 * Una excepción sin atrapar dejaba un **500 con el cuerpo vacío**: la pantalla
 * de error del navegador, sin decir qué pasó ni cómo volver. Pasa de verdad
 * —se lo vio con `empresa_usuarios.php`— cuando el servidor toca su límite de
 * conexiones simultáneas y `mysqli` contesta `Operation not permitted`.
 *
 * El portal del cliente ya hacía esto desde `backend/db.php`; el panel no tiene
 * un arranque equivalente, así que se engancha acá, que es por donde pasan sus
 * doce pantallas.
 */
function panel_pantalla_ante_fallos(): void {
    set_exception_handler(static function (Throwable $e): void {
        if (function_exists('platform_log')) {
            platform_log('PANEL sin atrapar (' . get_class($e) . '): ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
        /* Si ni siquiera se puede dibujar la pantalla, al menos un código y no
           una página en blanco. La ruta va literal —y no armada en una
           variable— para que `test/requires.php` pueda resolver de dónde sale
           `pantalla_sin_base()`. */
        if (!is_file(__DIR__ . '/../../includes/sin_base.php')) {
            if (!headers_sent()) { http_response_code(500); }
            return;
        }
        require_once __DIR__ . '/../../includes/sin_base.php';
        pantalla_sin_base(
            'No se puede abrir el panel en este momento',
            'La base de la plataforma no respondió. Es algo momentáneo: volvé a '
            . 'intentar en unos segundos. Si sigue igual, revisá el log de la plataforma.',
            '/dashboard/dashboard_resumen.php'
        );
    });
}

function superadmin_require_login(): void {
    panel_pantalla_ante_fallos();
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    if (empty($_SESSION[SESSION_SUPERADMIN_FLAG]) || $_SESSION[SESSION_SUPERADMIN_FLAG] !== true) {
        header('Location: /auth/superadmin_login.php');
        exit;
    }
    // Lazy-load del rol para sesiones anteriores al sistema de roles
    if (empty($_SESSION['superadmin_role']) && !empty($_SESSION['superadmin_user_id'])) {
        try {
            $db = new MasterDB();
            $st = $db->pquery('SELECT role FROM users_platform WHERE id = ? LIMIT 1', 'i', [(int)$_SESSION['superadmin_user_id']]);
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) { $_SESSION['superadmin_role'] = (string)$row['role']; }
        } catch (Throwable) {}
    }
    // Forzar configuración de SSO solo para superadmin
    if (!empty($_SESSION['pending_sso_setup']) && ($_SESSION['superadmin_role'] ?? '') === 'superadmin') {
        $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
        $allowed = ['superadmin_integraciones.php', 'superadmin_logout.php'];
        if (!in_array($currentPage, $allowed, true)) {
            header('Location: /dashboard/superadmin_integraciones.php');
            exit;
        }
    }
}

function superadmin_login(int $userId, string $role = ''): void {
    // Rotar el ID de sesión al autenticar: invalida cualquier PHPSESSID que un
    // atacante hubiera fijado antes del login (fijación de sesión).
    require_once __DIR__ . '/session_guard.php';
    app_session_rotar();

    $_SESSION[SESSION_SUPERADMIN_FLAG] = true;
    $_SESSION['superadmin_user_id']    = $userId;
    if ($role !== '') {
        $_SESSION['superadmin_role'] = $role;
    }
}

function superadmin_logout(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    $_SESSION = [];
    session_destroy();
}
