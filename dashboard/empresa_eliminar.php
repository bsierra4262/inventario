<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/security/csrf.php';
require_once __DIR__ . '/../core/db/master_connection.php';

superadmin_require_login();
csrf_start();

// Antes esta pantalla sólo pedía estar logueado: cualquier usuario de plataforma
// —incluido un `viewer`— podía dar de baja cualquier empresa con un POST, y sin
// token CSRF, así que además se podía disparar desde otro sitio.
if (!platform_can('company.delete')) {
    http_response_code(403);
    exit('Sin permiso para dar de baja empresas.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

csrf_validate();

$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'deactivate';

if ($id <= 0) {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

try {
    $db     = new MasterDB();
    $active = ($action === 'activate') ? 1 : 0;
    $db->pquery('UPDATE companies SET active = ? WHERE id = ?', 'ii', [$active, $id]);

    platform_log(sprintf(
        'empresa_eliminar: company_id=%d active=%d por superadmin_id=%s',
        $id, $active, (string)($_SESSION['superadmin_user_id'] ?? '?')
    ));

    $okParam = ($active === 1) ? 'empresa_activada' : 'empresa_eliminada';
    header("Location: /dashboard/dashboard_superadmin.php?ok=$okParam");
} catch (Throwable $e) {
    header('Location: /dashboard/dashboard_superadmin.php?err=' . urlencode($e->getMessage()));
}
exit;
