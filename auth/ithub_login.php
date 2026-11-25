<?php
/**
 * auth/ithub_login.php — Manda a ITHub a identificarse (core/security/ithub.php).
 *
 *   /auth/ithub_login.php                   → inventario de la empresa ITH
 *   /auth/ithub_login.php?portal=superadmin → panel SuperAdmin
 *
 * Si la persona ya tiene la sesión única abierta, ITHub devuelve el código al
 * instante y entra sin ver ninguna pantalla. La vuelta la atienden
 * ithub_callback.php y superadmin_ithub_callback.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config/platform.php';
require_once __DIR__ . '/../core/security/session_guard.php';
require_once __DIR__ . '/../core/security/ithub.php';
require_once __DIR__ . '/../includes/sin_base.php';

app_session_start();

$portal = (($_GET['portal'] ?? '') === 'superadmin') ? 'superadmin' : 'inventario';

if ($portal === 'superadmin') {
    if (!ithub_habilitado('superadmin')) {
        app_session_cerrar_y_redirigir('/auth/superadmin_login.php?manual=1');
    }
    if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
        app_session_cerrar_y_redirigir('/dashboard/dashboard_resumen.php');
    }
    app_session_cerrar_y_redirigir(ithub_url_autorizacion('superadmin'));
}

// Inventario: sólo para la empresa ITH. Las empresas clientes no pasan por acá.
if (!ithub_habilitado('inventario')) {
    app_session_cerrar_y_redirigir('/auth/login.php?manual=1');
}
try {
    $cid = ithub_empresa_id();
} catch (Throwable $e) {
    pantalla_sin_base('No se puede iniciar sesión en este momento');
}
if ($cid <= 0) {
    app_session_cerrar_y_redirigir('/auth/login.php?manual=1&err='
        . urlencode('La empresa que entra con ITHub no está activa. Avisale al administrador del sistema.'));
}

// Ya adentro de ITH: directo al inventario.
if (!empty($_SESSION['user_id']) && (int)($_SESSION['auth_company_id'] ?? 0) === $cid) {
    app_session_cerrar_y_redirigir('/inventario/index.php');
}

app_session_cerrar_y_redirigir(ithub_url_autorizacion('inventario'));
