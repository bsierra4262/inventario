<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_role.php';
require_once __DIR__ . '/../core/db/master_connection.php';

superadmin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard/dashboard_superadmin.php');
    exit;
}

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

    $okParam = ($active === 1) ? 'empresa_activada' : 'empresa_eliminada';
    header("Location: /dashboard/dashboard_superadmin.php?ok=$okParam");
} catch (Throwable $e) {
    header('Location: /dashboard/dashboard_superadmin.php?err=' . urlencode($e->getMessage()));
}
exit;
