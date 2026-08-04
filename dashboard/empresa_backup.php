<?php
/**
 * BACKUP COMPLETO DE EMPRESA (superadmin)
 *
 * Descarga un JSON con TODA la informacion del tenant para respaldo o migracion:
 *   - Datos de companies (DB master)
 *   - Productos (activos + archivados con todas las columnas)
 *   - Movimientos (historial completo)
 *   - Usuarios (con password hash y meta — sin nada en claro)
 *   - cloud_config (credenciales OAuth del tenant)
 *   - tenant_settings (toggles)
 *   - role_permissions (overrides)
 *   - custom_roles
 *
 * El archivo incluye un manifest con timestamp, version, company snapshot,
 * y conteos para validacion.
 *
 * Acceso: solo superadmin con permiso company.view
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/db/master_connection.php';
require_once __DIR__ . '/../core/tenants/install_tenant.php';

superadmin_require_login();

// El dump incluye `users` (hashes de contraseña) y `cloud_config` (client_secret
// de OAuth del tenant). Con `company.view` lo podía bajar hasta un rol `viewer`.
if (!platform_can('company.backup')) {
    http_response_code(403);
    echo 'Sin permiso para descargar respaldos.';
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'ID de empresa invalido.';
    exit;
}

// --- Cargar datos de la empresa desde master ---
$db   = new MasterDB();
$stmt = $db->pquery(
    'SELECT id, nombre, razon_social, cuit, provider, tenant_mode, db_host, db_name, db_user, subdomain, login_url, active, created_at
     FROM companies WHERE id = ? LIMIT 1',
    'i', [$id]
);
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$company) {
    http_response_code(404);
    echo 'Empresa no encontrada.';
    exit;
}

// --- Conectar al tenant ---
try {
    ['conn' => $tconn, 'prefix' => $pf] = tenant_conn_by_company($id);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'No se pudo conectar al tenant: ' . htmlspecialchars($e->getMessage());
    exit;
}

// Helper para traer todas las filas de una tabla
$dumpTable = function (mysqli $c, string $table): array {
    try {
        $rs = $c->query("SELECT * FROM `{$table}`");
        if (!$rs) return [];
        $out = [];
        while ($r = $rs->fetch_assoc()) { $out[] = $r; }
        return $out;
    } catch (Throwable) { return []; }
};

// --- Construir el dump ---
$backup = [
    'manifest' => [
        'app'             => 'AssetVault',
        'app_version'     => 'platform-1.0',
        'backup_format'   => 1,
        'generated_at'    => date('c'),
        'generated_by'    => $_SESSION['superadmin_username'] ?? ('id:' . ($_SESSION['superadmin_user_id'] ?? '?')),
        'company_id'      => (int)$company['id'],
        'company_name'    => (string)$company['nombre'],
        'tenant_prefix'   => $pf,
        'tenant_mode'     => (string)$company['tenant_mode'],
    ],
    'company'           => $company,
    'productos'         => $dumpTable($tconn, $pf . 'productos'),
    'producto_movimientos' => $dumpTable($tconn, $pf . 'producto_movimientos'),
    'users'             => $dumpTable($tconn, $pf . 'users'),
    'cloud_config'      => $dumpTable($tconn, $pf . 'cloud_config'),
    'tenant_settings'   => $dumpTable($tconn, $pf . 'tenant_settings'),
    'role_permissions'  => $dumpTable($tconn, $pf . 'role_permissions'),
    'custom_roles'      => $dumpTable($tconn, $pf . 'custom_roles'),
];

// Conteos en manifest para validacion rapida
$backup['manifest']['counts'] = [
    'productos'            => count($backup['productos']),
    'producto_movimientos' => count($backup['producto_movimientos']),
    'users'                => count($backup['users']),
    'cloud_config'         => count($backup['cloud_config']),
    'tenant_settings'      => count($backup['tenant_settings']),
    'role_permissions'     => count($backup['role_permissions']),
    'custom_roles'         => count($backup['custom_roles']),
];

// --- Descargar como JSON ---
$slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower((string)$company['nombre']));
$slug = trim($slug, '_') ?: ('company_' . $id);
$filename = 'assetvault_backup_' . $slug . '_' . date('Ymd_His') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
