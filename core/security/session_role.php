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
    $matrix = platform_permissions_matrix();
    if (!isset($matrix[$action])) return false;
    return in_array($role, $matrix[$action], true);
}

function superadmin_require_login(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    if (empty($_SESSION[SESSION_SUPERADMIN_FLAG]) || $_SESSION[SESSION_SUPERADMIN_FLAG] !== true) {
        header('Location: /auth/superadmin_login.php');
        exit;
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
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
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
