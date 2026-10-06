<?php
/**
 * ABM de la ficha técnica de un activo: sistema operativo, componentes, red,
 * relaciones, contratos, documentos y notas.
 *
 * Lo postea inventario/ficha.php. Es un solo endpoint para todas las secciones
 * de esa pantalla: separarlo en siete archivos sería repetir siete veces la
 * misma guardia de sesión, permiso y CSRF, y ya pasó que a cinco endpoints se
 * les olvidara una de las tres.
 *
 * Permiso: `asset.edit`. Cargar qué procesador tiene un equipo o a qué contrato
 * está asociado es editar el activo, aunque el dato viva en otra tabla.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../core/tenants/sistema_operativo.php';
require_once __DIR__ . '/../core/tenants/componentes.php';
require_once __DIR__ . '/../core/tenants/red.php';
require_once __DIR__ . '/../core/tenants/relaciones.php';
require_once __DIR__ . '/../core/tenants/contratos.php';
require_once __DIR__ . '/../core/tenants/documentos.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

/** Vuelve a la ficha con el resultado a la vista, en la sección donde se estaba. */
function ficha_responder(int $id, bool $ok, string $msg, string $ancla = ''): never {
    $url = '/inventario/ficha.php?id=' . $id
         . '&' . ($ok ? 'ok' : 'err') . '=' . urlencode($msg)
         . ($ancla !== '' ? '#' . $ancla : '');
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /inventario/index.php');
    exit;
}

// Sesión + permiso. No alcanza con el token CSRF: ese prueba que el pedido salió
// de nuestro formulario, no que quien lo mandó tenga derecho a la acción.
$me = require_tenant_permission('asset.edit');
$userId = (int)$me['id'];
$autor  = (string)($me['username'] ?? $me['email'] ?? '');

if (!verifyCsrfToken($_POST['csrf_token'] ?? '', 'ficha_abm')) {
    log_event((string)$userId, 'CSRF_BLOCK', 'Token inválido en la ficha técnica', 'seguridad');
    ficha_responder((int)($_POST['producto_id'] ?? 0), false, 'El formulario expiró. Volvé a cargar la página.');
}

$productoId = (int)($_POST['producto_id'] ?? 0);
if ($productoId <= 0) { ficha_responder(0, false, 'Falta el activo.'); }

// El activo tiene que existir en ESTE tenant. Sin esto, un id de otra empresa
// escrito a mano dejaría colgar componentes de un activo ajeno.
$st = $conn->prepare('SELECT id FROM `' . tn('productos') . '` WHERE id = ? LIMIT 1');
$st->bind_param('i', $productoId);
$st->execute();
$existe = (bool)$st->get_result()->fetch_assoc();
$st->close();
if (!$existe) { ficha_responder(0, false, 'Ese activo no existe.'); }

$accion = (string)($_POST['accion'] ?? '');

switch ($accion) {

    // ── Sistema operativo ───────────────────────────────────────────────────
    case 'so_guardar':
        $datos = [];
        foreach (array_merge(SO_CAMPOS_TEXTO, SO_CAMPOS_FECHA) as $c) {
            if (isset($_POST[$c])) { $datos[$c] = (string)$_POST[$c]; }
        }
        $ok = so_guardar($conn, $productoId, $datos);
        log_event((string)$userId, 'FICHA_SO', "Activo {$productoId}", 'inventario');
        ficha_responder($productoId, $ok,
            $ok ? 'Sistema operativo guardado.' : 'No se pudo guardar el sistema operativo.', 'so');

    // ── Componentes ─────────────────────────────────────────────────────────
    case 'componente_agregar':
        $tipo = (string)($_POST['tipo'] ?? '');
        $desig = trim((string)($_POST['designacion'] ?? ''));
        if ($desig === '') { ficha_responder($productoId, false, 'Poné el nombre del componente.', 'componentes'); }

        $compId = componente_alta($conn, $tipo, $desig, (string)($_POST['fabricante'] ?? ''));
        if ($compId === 0) { ficha_responder($productoId, false, 'No se pudo dar de alta el componente.', 'componentes'); }

        $inst = [];
        foreach (array_keys(COMPONENTE_CAMPOS_INSTANCIA) as $c) {
            if (isset($_POST[$c])) { $inst[$c] = (string)$_POST[$c]; }
        }
        $asig = componente_asignar($conn, $productoId, $compId, $inst);
        log_event((string)$userId, 'FICHA_COMPONENTE', "Activo {$productoId}: +{$desig}", 'inventario');
        ficha_responder($productoId, $asig > 0,
            $asig > 0 ? 'Componente agregado.' : 'No se pudo agregar el componente.', 'componentes');

    case 'componente_quitar':
        $ok = componente_quitar($conn, (int)($_POST['asignacion_id'] ?? 0));
        ficha_responder($productoId, $ok,
            $ok ? 'Componente quitado del activo.' : 'No se pudo quitar.', 'componentes');

    // ── Red ─────────────────────────────────────────────────────────────────
    case 'puerto_guardar':
        $d = [];
        foreach (['numero', 'nombre', 'tipo', 'mac', 'velocidad_mbps', 'mtu', 'vlan', 'estado', 'nota'] as $c) {
            $d[$c] = (string)($_POST[$c] ?? '');
        }
        $pid = red_puerto_guardar($conn, $productoId, (int)($_POST['puerto_id'] ?? 0), $d);
        ficha_responder($productoId, $pid > 0,
            $pid > 0 ? 'Puerto guardado.' : 'No se pudo guardar el puerto.', 'red');

    case 'puerto_borrar':
        $ok = red_puerto_borrar($conn, $productoId, (int)($_POST['puerto_id'] ?? 0));
        ficha_responder($productoId, $ok,
            $ok ? 'Puerto eliminado, con sus direcciones.' : 'No se pudo eliminar.', 'red');

    case 'ip_agregar':
        // El puerto tiene que ser de ESTE activo: con el id a mano se podría
        // sumar una dirección al puerto de otro equipo.
        $puertoId = (int)($_POST['puerto_id'] ?? 0);
        $propios = array_column(red_puertos_de($conn, $productoId), 'id');
        if (!in_array($puertoId, array_map('intval', $propios), true)) {
            ficha_responder($productoId, false, 'Ese puerto no es de este activo.', 'red');
        }
        $d = [];
        foreach (['ip', 'mascara', 'gateway', 'asignacion', 'nombre_dns'] as $c) {
            $d[$c] = (string)($_POST[$c] ?? '');
        }
        $ipId = red_ip_guardar($conn, $puertoId, $d);
        ficha_responder($productoId, $ipId > 0,
            $ipId > 0 ? 'Dirección agregada.' : 'La dirección IP no es válida.', 'red');

    case 'ip_borrar':
        $ok = red_ip_borrar($conn, (int)($_POST['ip_id'] ?? 0));
        ficha_responder($productoId, $ok, $ok ? 'Dirección eliminada.' : 'No se pudo eliminar.', 'red');

    // ── Relaciones ──────────────────────────────────────────────────────────
    case 'relacion_crear':
        $r = relacion_crear($conn, $productoId, (int)($_POST['destino_id'] ?? 0),
                            (string)($_POST['tipo'] ?? ''), (string)($_POST['nota'] ?? ''));
        ficha_responder($productoId, $r['ok'] ?? false,
            ($r['ok'] ?? false) ? 'Activos vinculados.' : (string)($r['error'] ?? 'No se pudo vincular.'),
            'relaciones');

    case 'relacion_borrar':
        $ok = relacion_borrar($conn, (int)($_POST['relacion_id'] ?? 0));
        ficha_responder($productoId, $ok, $ok ? 'Vínculo eliminado.' : 'No se pudo eliminar.', 'relaciones');

    // ── Contratos ───────────────────────────────────────────────────────────
    case 'contrato_vincular':
        $ok = contrato_vincular($conn, (int)($_POST['contrato_id'] ?? 0), $productoId);
        ficha_responder($productoId, $ok,
            $ok ? 'Contrato asociado al activo.' : 'No se pudo asociar.', 'contratos');

    case 'contrato_desvincular':
        $ok = contrato_desvincular($conn, (int)($_POST['contrato_id'] ?? 0), $productoId);
        ficha_responder($productoId, $ok, $ok ? 'Contrato desvinculado.' : 'No se pudo desvincular.', 'contratos');

    // ── Documentos ──────────────────────────────────────────────────────────
    case 'documento_agregar':
        $d = [];
        foreach (['nombre', 'tipo', 'enlace', 'etiqueta', 'nota'] as $c) {
            $d[$c] = (string)($_POST[$c] ?? '');
        }
        $d['subido_por'] = $autor;
        $r = documento_guardar($conn, 0, $d);
        if (!($r['ok'] ?? false)) {
            ficha_responder($productoId, false, (string)($r['error'] ?? 'No se pudo guardar.'), 'documentos');
        }
        documento_vincular($conn, (int)$r['id'], $productoId);
        ficha_responder($productoId, true, 'Documento agregado.', 'documentos');

    case 'documento_borrar':
        $ok = documento_borrar($conn, (int)($_POST['documento_id'] ?? 0));
        ficha_responder($productoId, $ok,
            $ok ? 'Documento eliminado. El archivo en la nube no se tocó.' : 'No se pudo eliminar.',
            'documentos');

    // ── Notas ───────────────────────────────────────────────────────────────
    case 'nota_agregar':
        $id = nota_agregar($conn, $productoId, (string)($_POST['texto'] ?? ''), $autor);
        ficha_responder($productoId, $id > 0,
            $id > 0 ? 'Nota agregada.' : 'La nota está vacía.', 'notas');

    case 'nota_borrar':
        $ok = nota_borrar($conn, (int)($_POST['nota_id'] ?? 0));
        ficha_responder($productoId, $ok, $ok ? 'Nota eliminada.' : 'No se pudo eliminar.', 'notas');

    default:
        ficha_responder($productoId, false, 'Acción no reconocida.');
}
