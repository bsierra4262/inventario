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
            // Papelera: sacar un activo de archivados y devolverlo al inventario.
            'asset.restore'         => ['admin', 'manager'],
            'asset.trash.view'      => ['admin', 'manager'],
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

            // Catálogos: quién puede dar de alta valores de "Asignado a" y
            // "Ubicación". Un usuario común elige de la lista, no la amplía.
            'catalog.manage'        => ['admin'],

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
                // Load lazy del modulo DB si no esta cargado
                if (!function_exists('role_perms_effective_v2')) {
                    $pdbPath = __DIR__ . '/permissions_db.php';
                    if (is_file($pdbPath)) require_once $pdbPath;
                }
                if (function_exists('role_perms_effective_v2')) {
                    // v2 maneja builtin (DB con fallback default) y custom (solo DB, sin fallback)
                    $cache[$key] = role_perms_effective_v2($conn, $role);
                } elseif (function_exists('role_perms_effective')) {
                    $cache[$key] = role_perms_effective($conn, $role);
                } else {
                    $cache[$key] = null;
                }
            }
            if (is_array($cache[$key])) {
                return in_array($action, $cache[$key], true);
            }
        }

        // Fallback: matriz default (solo aplica a builtin roles)
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
            // platform_logo_html() vive en core/config/platform.php. Los endpoints
            // del flujo de duplicados (renombrar, sobrescribir) no lo incluyen, y
            // sin este guard el 403 moría con un "undefined function" — un fatal
            // en lugar de la pantalla de acceso denegado.
            $logo = function_exists('platform_logo_html') ? platform_logo_html() : 'i';
            ?>
            <!doctype html>
            <html lang="es"><head><meta charset="utf-8"><title>403</title>
            <link href="https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700;800&display=swap" rel="stylesheet">
            <link href="/assets/css/app.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
            </head><body>
            <div class="ih-auth">
              <div class="ih-auth__card">
                <div class="ih-auth__header">
                  <div class="ih-auth__brand">
                    <div class="ih-auth__logo"><?= $logo ?></div>
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
 * Usuario del tenant autenticado, leído de la base (no de la sesión).
 *
 * El rol se lee de `tn('users')` en cada request a propósito: si el admin le
 * baja el rol a alguien que ya tiene la sesión abierta, el cambio tiene efecto
 * en el request siguiente y no cuando se le vence la cookie.
 *
 * Devuelve null si no hay sesión de tenant o si el usuario ya no existe.
 */
if (!function_exists('current_tenant_user')) {
    function current_tenant_user(): ?array {
        global $conn;

        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0 || !isset($conn) || !($conn instanceof mysqli) || !function_exists('tn')) {
            return null;
        }

        try {
            $stmt = $conn->prepare('SELECT id, username, email, role FROM `' . tn('users') . '` WHERE id = ? LIMIT 1');
            if (!$stmt) { return null; }
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}

/**
 * Guardia completa para un endpoint del tenant: exige sesión y permiso.
 *
 * Reemplaza el bloque de seis líneas (prepare + bind + execute + fetch +
 * close + require_permission) que estaba copiado en cada archivo de backend/.
 * Estaba copiado en ocho, y faltaba en cinco: renombrar.php, sobrescribir.php,
 * upload_to_drive.php, upload_renamed.php y confirmar_accion.php dejaban pasar
 * a cualquier usuario autenticado, incluido el rol `viewer`.
 *
 * Devuelve el usuario para que el llamador no tenga que volver a buscarlo.
 */
if (!function_exists('require_tenant_permission')) {
    function require_tenant_permission(string $action): array {
        $me = current_tenant_user();

        if ($me === null) {
            // Sin sesión válida no hay a quién pedirle permiso: al login del tenant.
            $helper = __DIR__ . '/../tenants/tenant_url_helper.php';
            if (is_file($helper)) {
                require_once $helper;
                if (function_exists('tenant_login_url')) {
                    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
                    exit;
                }
            }
            http_response_code(403);
            echo 'Sesión no válida.';
            exit;
        }

        require_permission($action, $me);
        return $me;
    }
}

/**
 * Guardia para una pantalla que AGRUPA a otras: alcanza con poder entrar a una.
 *
 * Las pantallas Datos y Configuración no hacen nada por sí mismas: muestran las
 * opciones que hay debajo. Pedirles un permiso fijo sería arbitrario —quien
 * puede administrar usuarios pero no catálogos tendría que poder entrar igual—,
 * y no pedir ninguno dejaría ver el mapa completo del sistema a un rol de sólo
 * lectura.
 *
 * Cada opción de adentro vuelve a chequear su propio permiso: esto decide quién
 * ve el índice, no quién entra a cada cosa.
 */
if (!function_exists('require_tenant_permission_any')) {
    function require_tenant_permission_any(array $acciones): array {
        $me = current_tenant_user();

        if ($me === null) {
            $helper = __DIR__ . '/../tenants/tenant_url_helper.php';
            if (is_file($helper)) {
                require_once $helper;
                if (function_exists('tenant_login_url')) {
                    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
                    exit;
                }
            }
            http_response_code(403);
            echo 'Sesión no válida.';
            exit;
        }

        foreach ($acciones as $a) {
            if (user_can((string)$a, $me)) { return $me; }
        }

        // Ninguno: se reusa la pantalla de acceso denegado pidiendo el primero,
        // que es el que mejor describe la sección.
        require_permission((string)($acciones[0] ?? 'asset.view'), $me);
        return $me;   // inalcanzable: require_permission corta con exit
    }
}

/**
 * Devuelve la etiqueta legible del rol. Soporta builtin + custom (DB).
 * NUNCA retorna string vacio — si el role esta vacio o no se encuentra,
 * devuelve algo legible para evitar badges en blanco.
 */
if (!function_exists('role_label')) {
    function role_label(string $role): string {
        if ($role === '' || $role === null) return 'Sin rol';
        if (isset(TENANT_ROLE_LABELS[$role])) return TENANT_ROLE_LABELS[$role];
        // Custom roles: leer de DB si hay $conn global
        global $conn;
        if (isset($conn) && $conn instanceof mysqli) {
            static $cache = null;
            if ($cache === null) {
                $cache = [];
                if (!function_exists('custom_roles_all')) {
                    $pdbPath = __DIR__ . '/permissions_db.php';
                    if (is_file($pdbPath)) require_once $pdbPath;
                }
                if (function_exists('custom_roles_all')) {
                    $cache = custom_roles_all($conn);
                }
            }
            if (isset($cache[$role])) return $cache[$role];
        }
        // Fallback: capitalizar el role_key para que al menos se vea algo
        $pretty = ucwords(str_replace(['_', '-'], ' ', $role));
        return $pretty !== '' ? $pretty : 'Sin rol';
    }
}
