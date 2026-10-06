<?php
/**
 * ABM de las definiciones de campos específicos por categoría.
 *
 * Lo postea inventario/campos.php. Todas las acciones exigen `catalog.manage`:
 * definir qué se le pregunta al usuario en el alta es la misma clase de decisión
 * que mantener las listas, así que comparte permiso con los catálogos.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../core/tenants/campos.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

/** Vuelve a la pantalla con el resultado a la vista. */
function campos_responder(bool $ok, string $msg): never {
    header('Location: /inventario/campos.php?' . ($ok ? 'ok' : 'err') . '=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    campos_responder(false, 'Método no permitido.');
}

// Sesión + permiso. No alcanza con el token CSRF: ese prueba que el pedido salió
// de nuestro formulario, no que quien lo mandó tenga derecho a la acción.
$me = require_tenant_permission('catalog.manage');
$userId = (int)$me['id'];

if (!verifyCsrfToken($_POST['csrf_token'] ?? '', 'campos_abm')) {
    log_event((string)$userId, 'CSRF_BLOCK', 'Token inválido en el ABM de campos', 'seguridad');
    campos_responder(false, 'El formulario expiró. Volvé a cargar la página.');
}

$accion = (string)($_POST['accion'] ?? '');

switch ($accion) {

    case 'agregar':
        $clave = campo_clave_normalizar((string)($_POST['clave'] ?? ''));
        if ($clave === '') {
            campos_responder(false, 'El identificador no puede quedar vacío.');
        }
        // Una clave que coincide con una columna de `productos` taparía al campo
        // heredado que usa esa columna.
        if (campos_clave_reservada($conn, $clave)) {
            campos_responder(false, "«{$clave}» ya es un campo del activo. Elegí otro identificador.");
        }
        $tipo = (string)($_POST['tipo'] ?? 'texto');
        if ($tipo === 'lista' && trim((string)($_POST['opciones'] ?? '')) === '') {
            campos_responder(false, 'Un campo de tipo lista necesita al menos una opción.');
        }

        $id = campo_definicion_agregar($conn, [
            'clave'        => $clave,
            'etiqueta'     => (string)($_POST['etiqueta'] ?? ''),
            'tipo'         => $tipo,
            'opciones'     => (string)($_POST['opciones'] ?? ''),
            'categoria'    => (string)($_POST['categoria'] ?? ''),
            'subcategoria' => (string)($_POST['subcategoria'] ?? ''),
            'requerido'    => !empty($_POST['requerido']),
            'orden'        => (int)($_POST['orden'] ?? 0),
        ]);
        if ($id <= 0) {
            campos_responder(false, 'No se pudo crear el campo. Puede que ya exista uno con ese identificador en el mismo ámbito.');
        }
        log_event((string)$userId, 'CAMPOS', "Campo «{$clave}» creado", 'catalogos');
        campos_responder(true, 'Campo creado. Ya aparece en el formulario de los activos de esa categoría.');

    case 'activar':
    case 'desactivar':
        $id = (int)($_POST['id'] ?? 0);
        $on = ($accion === 'activar');
        if (!campo_definicion_activar($conn, $id, $on)) {
            campos_responder(false, 'No se pudo cambiar el estado del campo.');
        }
        log_event((string)$userId, 'CAMPOS', "Campo id={$id} " . ($on ? 'mostrado' : 'oculto'), 'catalogos');
        campos_responder(true, $on
            ? 'El campo vuelve a mostrarse en el formulario.'
            : 'El campo deja de mostrarse. Los activos que ya lo tenían cargado conservan el valor.');

    case 'eliminar':
        $id = (int)($_POST['id'] ?? 0);
        if (!campo_definicion_eliminar($conn, $id)) {
            campos_responder(false, 'No se puede eliminar: el campo está en uso, o es uno de los que tienen columna propia.');
        }
        log_event((string)$userId, 'CAMPOS', "Campo id={$id} eliminado", 'catalogos');
        campos_responder(true, 'Campo eliminado.');

    default:
        campos_responder(false, 'Acción no reconocida.');
}
