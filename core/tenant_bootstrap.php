<?php
/**
 * Bootstrap multi-empresa para scripts del sistema.
 * Incluilo en lugar de backend/db.php (o dentro de ese archivo como "shim").
 *
 * Expone:
 *   - $tenantDb  : instancia TenantDB
 *   - $conn      : alias mysqli (retrocompatibilidad con tu código actual)
 *   - $_SESSION['company_id'] asegurado
 */
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/config/platform.php';
require_once __DIR__ . '/db/master_connection.php';
require_once __DIR__ . '/db/TenantDB.php';

// Resolver empresa en sesión.
// Si no hay, tomamos: (a) única empresa existente; (b) si hay varias -> pedimos selección.
function resolve_company_id(): int {
    if (!empty($_SESSION['company_id'])) {
        return (int)$_SESSION['company_id'];
    }
    $mdb = new MasterDB();
    $res = $mdb->pquery('SELECT id FROM companies ORDER BY id ASC');
    $ids = [];
    $rs = $res->get_result();
    while($r = $rs->fetch_assoc()){ $ids[] = (int)$r['id']; }
    if(count($ids) === 0){
        http_response_code(500);
        die('No hay empresas cargadas en la plataforma.');
    }
    if(count($ids) === 1){
        $_SESSION['company_id'] = $ids[0];
        return $ids[0];
    }

    // Si hay varias, redirigir a un selector simple (podemos mejorarlo luego)
    $_SESSION['pending_target'] = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: /dashboard/select_empresa.php');
    exit;
}

$companyId = resolve_company_id();
$tenantDb  = new TenantDB($companyId);

// Retrocompatibilidad: muchos scripts esperan $conn (mysqli)
$conn = $tenantDb->conn;

// También exponemos helper de tabla para modo 'prefix' (no necesario si usás 'external'):
function tn(string $name): string {
    global $tenantDb;
    return $tenantDb->t($name);
}
