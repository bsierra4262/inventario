<?php
/**
 * ABM de catálogos del tenant ("Asignado a" y "Ubicación").
 *
 * Dos modos:
 *   - AJAX  (?ajax=1)  → alta rápida desde el formulario de alta/edición de activos.
 *                        Devuelve JSON. Sólo la acción `agregar`.
 *   - Form  (POST)     → desde inventario/catalogos.php. Redirige con feedback.
 *
 * Todas las acciones exigen el permiso `catalog.manage` (por defecto: admin).
 * Un usuario común elige de la lista; no la amplía.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../core/tenants/catalogos.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

$esAjax = !empty($_GET['ajax']);

/** Respuesta uniforme para los dos modos. */
function cat_responder(bool $ok, string $msg, array $extra = []): never {
    global $esAjax;
    if ($esAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $destino = '/inventario/catalogos.php?tipo=' . urlencode((string)($_POST['tipo'] ?? 'asignado_a'))
             . '&' . ($ok ? 'ok' : 'err') . '=' . urlencode($msg);
    header('Location: ' . $destino);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cat_responder(false, 'Método no permitido.');
}

// ── Sesión y permiso ─────────────────────────────────────────────────────────
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    if ($esAjax) { http_response_code(401); }
    cat_responder(false, 'Sesión expirada. Volvé a iniciar sesión.');
}

$usersTable = tn('users');
$st = $conn->prepare("SELECT id, role FROM `{$usersTable}` WHERE id = ? LIMIT 1");
$st->bind_param('i', $userId);
$st->execute();
$me = $st->get_result()->fetch_assoc();
$st->close();

if (!$me || !user_can('catalog.manage', $me)) {
    if ($esAjax) { http_response_code(403); }
    log_event((string)$userId, 'PERMISO_DENEGADO', 'Intento de gestionar catálogos sin permiso', 'seguridad');
    cat_responder(false, 'No tenés permiso para gestionar las listas.');
}

// ── CSRF ─────────────────────────────────────────────────────────────────────
// El alta rápida manda el token por header; la pantalla de gestión, por POST.
$scope = $esAjax ? 'catalogo_ajax' : 'catalogos_abm';
if (!verifyCsrfToken(null, $scope)) {
    if ($esAjax) { http_response_code(419); }
    cat_responder(false, 'Token de seguridad inválido o vencido. Recargá la página.');
}

// ── Acción ───────────────────────────────────────────────────────────────────
$accion = (string)($_POST['accion'] ?? 'agregar');
$tipo   = (string)($_POST['tipo'] ?? '');

if (!catalogo_tipo_valido($tipo)) {
    cat_responder(false, 'Tipo de lista desconocido.');
}
$etiqueta = CATALOGO_TIPOS[$tipo]['label'];

switch ($accion) {

    case 'agregar':
        $valor = (string)($_POST['valor'] ?? '');
        // Sólo para los catálogos en árbol: de qué nodo cuelga el valor nuevo.
        // Vacío = ubicación principal. El id se valida dentro de la capa de
        // ubicaciones contra el árbol real; acá sólo se castea.
        $padreId = ($_POST['padre_id'] ?? '') !== '' ? (int)$_POST['padre_id'] : null;
        if (trim($valor) === '') {
            cat_responder(false, 'Escribí un valor.');
        }
        if (!catalogo_agregar($conn, $tipo, $valor, $padreId)) {
            cat_responder(false, 'No se pudo agregar (¿vacío o más de 255 caracteres?).');
        }
        $valorLimpio = trim(preg_replace('/\s+/u', ' ', $valor));
        log_event((string)$userId, 'CATALOGO_ALTA', "{$tipo}: {$valorLimpio}", 'catalogos');
        cat_responder(true, "«{$valorLimpio}» agregado a {$etiqueta}.", ['valor' => $valorLimpio]);

    case 'renombrar':
        $id    = (int)($_POST['id'] ?? 0);
        $nuevo = (string)($_POST['valor'] ?? '');
        if (!catalogo_renombrar($conn, $tipo, $id, $nuevo)) {
            cat_responder(false, 'No se pudo renombrar.');
        }
        log_event((string)$userId, 'CATALOGO_RENOMBRA', "{$tipo} id={$id} → {$nuevo}", 'catalogos');
        cat_responder(true, "Renombrado. Los activos que lo usaban quedaron actualizados.");

    case 'activar':
    case 'desactivar':
        $id = (int)($_POST['id'] ?? 0);
        $on = ($accion === 'activar');
        if (!catalogo_activar($conn, $tipo, $id, $on)) {
            cat_responder(false, 'No se pudo cambiar el estado.');
        }
        log_event((string)$userId, 'CATALOGO_ESTADO', "{$tipo} id={$id} activo=" . ($on ? '1' : '0'), 'catalogos');
        cat_responder(true, $on ? 'Valor reactivado.' : 'Valor dado de baja: ya no aparece en los desplegables.');

    case 'eliminar':
        $id = (int)($_POST['id'] ?? 0);
        if (!catalogo_eliminar($conn, $tipo, $id)) {
            cat_responder(false, 'No se puede eliminar: hay activos usando ese valor. Dalo de baja en su lugar.');
        }
        log_event((string)$userId, 'CATALOGO_BAJA', "{$tipo} id={$id} eliminado", 'catalogos');
        cat_responder(true, 'Valor eliminado.');

    default:
        cat_responder(false, 'Acción desconocida.');
}
