<?php
/**
 * Permisos por rol — TENANT
 *
 * Modelo:
 *   admin    → control total (incluye gestión de usuarios, integraciones, backup, eliminar)
 *   manager  → CRUD completo de activos + reportes + exportar; no toca usuarios ni integraciones
 *   user     → puede ver, agregar y editar; no archiva, no elimina, no exporta config
 *   viewer   → solo lectura
 *
 * Uso:
 *   if (!user_can('asset.delete', $current_user)) { http_response_code(403); exit; }
 */

declare(strict_types=1);

if (!defined('TENANT_ROLES')) {
    define('TENANT_ROLES', ['admin', 'manager', 'user', 'viewer']);
}

if (!defined('TENANT_ROLE_LABELS')) {
    define('TENANT_ROLE_LABELS', [
        'admin'   => 'Administrador',
        'manager' => 'Gerente',
        'user'    => 'Usuario',
        'viewer'  => 'Solo lectura',
    ]);
}

/**
 * Matriz de permisos.
 * Clave = acción canónica
 * Valor = lista de roles autorizados
 */
if (!function_exists('tenant_permissions_matrix')) {
    function tenant_permissions_matrix(): array {
        return [
            // Activos / inventario
            'asset.view'            => ['admin', 'manager', 'user', 'viewer'],
            'asset.create'          => ['admin', 'manager', 'user'],
            'asset.edit'            => ['admin', 'manager', 'user'],
            'asset.archive'         => ['admin', 'manager'],
            'asset.delete'          => ['admin'],
            'asset.export'          => ['admin', 'manager'],
            'asset.import_csv'      => ['admin', 'manager'],
            'asset.backup_full'     => ['admin'],

            // Reportes
            'report.view'           => ['admin', 'manager', 'user', 'viewer'],

            // Usuarios del tenant
            'user.view'             => ['admin'],
            'user.create'           => ['admin'],
            'user.edit'             => ['admin'],
            'user.delete'           => ['admin'],

            // Integraciones (Drive / cloud)
            'integration.view'      => ['admin'],
            'integration.edit'      => ['admin'],

            // Configuración propia
            'self.change_password'  => ['admin', 'manager', 'user', 'viewer'],
        ];
    }
}

/**
 * Devuelve true si el rol indicado puede realizar la acción.
 *
 * Si hay overrides en DB (tabla tn('role_permissions')) los respeta.
 * Sino usa la matriz default hardcoded.
 *
 * El check de DB requiere $conn (variable global del tenant activo). Si no
 * existe (ej: scripts CLI sin tenant), usa solo la matriz default.
 */
if (!function_exists('role_can')) {
    function role_can(string $action, string $role): bool {
        global $conn;

        // Si hay conexion tenant, chequear overrides en DB
        if (isset($conn) && $conn instanceof mysqli) {
            static $cache = [];
            $key = $role;
            if (!isset($cache[$key])) {
                if (function_exists('role_perms_effective')) {
                    $cache[$key] = role_perms_effective($conn, $role);
                } else {
                    // permissions_db.php no cargado aun — load lazy
                    $pdbPath = __DIR__ . '/permissions_db.php';
                    if (is_file($pdbPath)) {
                        require_once $pdbPath;
                        $cache[$key] = role_perms_effective($conn, $role);
                    } else {
                        $cache[$key] = null;
                    }
                }
            }
            if (is_array($cache[$key])) {
                return in_array($action, $cache[$key], true);
            }
        }

        // Fallback: matriz default
        $matrix = tenant_permissions_matrix();
        if (!isset($matrix[$action])) return false;
        return in_array($role, $matrix[$action], true);
    }
}

/**
 * Devuelve true si el usuario (array de DB o de sesión) puede realizar la acción.
 * Acepta tanto user array como string role directo.
 */
if (!function_exists('user_can')) {
    function user_can(string $action, $userOrRole): bool {
        $role = '';
        if (is_array($userOrRole)) {
            $role = (string)($userOrRole['role'] ?? '');
        } elseif (is_string($userOrRole)) {
            $role = $userOrRole;
        }
        if ($role === '') return false;
        return role_can($action, $role);
    }
}

/**
 * Helper para usar en páginas: aborta con 403 si no tiene permiso.
 * Útil al inicio de archivos PHP que requieren autorización.
 */
if (!function_exists('require_permission')) {
    function require_permission(string $action, $userOrRole): void {
        if (!user_can($action, $userOrRole)) {
            http_response_code(403);
            ?>
            <!doctype html>
            <html lang="es"><head><meta charset="utf-8"><title>403</title>
            <link href="/assets/css/intellihelp.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
            </head><body>
            <div class="ih-auth">
              <div class="ih-auth__card">
                <div class="ih-auth__header">
                  <div class="ih-auth__brand">
                    <div class="ih-auth__logo"><?= platform_logo_html() ?></div>
                    <div class="ih-auth__brand-text">
                      <strong>INTELLIHELP</strong>
                      <span>Asset Vault</span>
                    </div>
                  </div>
                  <h2 class="ih-auth__title">Acceso denegado</h2>
                  <p class="ih-auth__subtitle">No tenés permisos para esta acción</p>
                </div>
                <div class="ih-auth__body">
                  <div class="ih-alert ih-alert--danger">
                    <i class="bi bi-shield-x"></i>
                    <div>Esta función requiere un rol superior al tuyo. Pedile al admin del tenant que te asigne más permisos.</div>
                  </div>
                  <a href="/inventario/index.php" class="ih-btn ih-btn--primary ih-btn--block">
                    <i class="bi bi-arrow-left"></i> Volver al inventario
                  </a>
                </div>
              </div>
            </div>
            </body></html>
            <?php
            exit;
        }
    }
}

/**
 * Devuelve la etiqueta legible del rol.
 */
if (!function_exists('role_label')) {
    function role_label(string $role): string {
        return TENANT_ROLE_LABELS[$role] ?? ucfirst($role);
    }
}
