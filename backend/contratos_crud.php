<?php
/**
 * ABM de proveedores y contratos.
 *
 * Lo postea inventario/contratos.php. Permiso `asset.edit`: un contrato decide
 * hasta cuándo está cubierto un activo, así que es la misma clase de decisión
 * que editar el activo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../core/tenants/contratos.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

/** Vuelve a la pantalla con el resultado a la vista. */
function contratos_responder(bool $ok, string $msg, string $ancla = ''): never {
    header('Location: /inventario/contratos.php?' . ($ok ? 'ok' : 'err') . '=' . urlencode($msg)
           . ($ancla !== '' ? '#' . $ancla : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    contratos_responder(false, 'Método no permitido.');
}

// Sesión + permiso. No alcanza con el token CSRF: ese prueba que el pedido salió
// de nuestro formulario, no que quien lo mandó tenga derecho a la acción.
$me = require_tenant_permission('asset.edit');
$userId = (int)$me['id'];

if (!verifyCsrfToken($_POST['csrf_token'] ?? '', 'contratos_abm')) {
    log_event((string)$userId, 'CSRF_BLOCK', 'Token inválido en el ABM de contratos', 'seguridad');
    contratos_responder(false, 'El formulario expiró. Volvé a cargar la página.');
}

$accion = (string)($_POST['accion'] ?? '');

switch ($accion) {

    case 'proveedor_guardar': {
        $d = [];
        foreach (['nombre', 'cuit', 'contacto', 'telefono', 'email', 'web', 'direccion', 'nota'] as $c) {
            $d[$c] = (string)($_POST[$c] ?? '');
        }
        $r = proveedor_guardar($conn, (int)($_POST['id'] ?? 0), $d);
        if ($r['ok'] ?? false) {
            log_event((string)$userId, 'PROVEEDOR_ALTA', (string)$d['nombre'], 'inventario');
        }
        contratos_responder($r['ok'] ?? false,
            ($r['ok'] ?? false) ? 'Proveedor guardado.' : (string)($r['error'] ?? 'No se pudo guardar.'),
            'proveedores');
    }

    case 'proveedor_baja': {
        $r = proveedor_baja($conn, (int)($_POST['id'] ?? 0));
        if (!($r['ok'] ?? false)) {
            contratos_responder(false, (string)($r['error'] ?? 'No se pudo dar de baja.'), 'proveedores');
        }
        contratos_responder(true,
            ($r['desactivado'] ?? false)
                ? 'El proveedor tiene ' . (int)($r['contratos'] ?? 0) . ' contrato(s): queda desactivado y sale de la lista.'
                : 'Proveedor eliminado.',
            'proveedores');
    }

    case 'contrato_guardar': {
        $d = [];
        foreach (['nombre', 'numero', 'tipo', 'proveedor_id', 'inicio', 'duracion_meses',
                  'preaviso_dias', 'periodicidad_meses', 'facturacion_meses', 'renovacion',
                  'numero_contable', 'costo', 'moneda', 'nota'] as $c) {
            $d[$c] = (string)($_POST[$c] ?? '');
        }
        $r = contrato_guardar($conn, (int)($_POST['id'] ?? 0), $d);
        if ($r['ok'] ?? false) {
            log_event((string)$userId, 'CONTRATO_ALTA', (string)$d['nombre'], 'inventario');
        }
        contratos_responder($r['ok'] ?? false,
            ($r['ok'] ?? false) ? 'Contrato guardado.' : (string)($r['error'] ?? 'No se pudo guardar.'),
            'contratos');
    }

    case 'contrato_baja': {
        $ok = contrato_baja($conn, (int)($_POST['id'] ?? 0));
        contratos_responder($ok,
            $ok ? 'Contrato dado de baja. Queda guardado con sus activos.' : 'No se pudo dar de baja.',
            'contratos');
    }

    default:
        contratos_responder(false, 'Acción no reconocida.');
}
