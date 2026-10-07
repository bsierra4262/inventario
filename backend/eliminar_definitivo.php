<?php
/**
 * Elimina un activo de verdad: borra la fila y todo lo que colgaba de ella.
 *
 * Existe porque faltaba la otra mitad de la papelera. `asset.delete` —«Eliminar
 * definitivamente»— figuraba en la pantalla de roles desde el principio, se
 * podía tildar y destildar, y no hacía absolutamente nada: no había ni una
 * pantalla ni un endpoint que lo consultara. La papelera se podía llenar y
 * nunca vaciar.
 *
 * Tres condiciones, y las tres importan:
 *
 *   1. El activo tiene que estar archivado. Desde el listado principal no se
 *      llega acá: primero se archiva (que se deshace), y sólo después se puede
 *      eliminar (que no). Así no hay un botón que destruya algo de un clic.
 *   2. Hay que escribir el número del activo para confirmar. Un `confirm()` se
 *      acepta sin leerlo; copiar un número obliga a mirar cuál.
 *   3. El historial sobrevive. `producto_movimientos` guarda qué equipo era, de
 *      quién, con qué service tag, y queda una última fila que dice que se
 *      eliminó y quién lo hizo. Si mañana aparece una auditoría de bienes, la
 *      respuesta no puede ser «no sé, alguien lo borró».
 *
 * La carpeta de Drive NO se toca. Borrar los archivos de alguien desde acá
 * sería una sorpresa desagradable: queda donde está, en «Archivados», y el
 * mensaje de vuelta lo aclara.
 *
 * Permiso: asset.delete (por defecto, sólo admin).
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/security/session_guard.php';
app_session_start();

require_once __DIR__ . '/../core/config/platform.php';
if (!empty($_SESSION[SESSION_SUPERADMIN_FLAG]) && $_SESSION[SESSION_SUPERADMIN_FLAG] === true) {
    header('Location: /dashboard/dashboard_superadmin.php'); exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../core/security/permissions.php';
require_once __DIR__ . '/../core/tenants/papelera.php';
if (is_file(__DIR__ . '/../logs/logger.php')) require_once __DIR__ . '/../logs/logger.php';
if (!function_exists('log_event')) { function log_event(...$a) {} }

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    require_once __DIR__ . '/../core/tenants/tenant_url_helper.php';
    header('Location: ' . tenant_login_url((int)($_SESSION['company_id'] ?? 0)));
    exit;
}

$ut = tn('users');
$st = $conn->prepare("SELECT id, username, role FROM `{$ut}` WHERE id = ? LIMIT 1");
$st->bind_param('i', $user_id);
$st->execute();
$me = $st->get_result()->fetch_assoc();
$st->close();
require_permission('asset.delete', $me);

function eliminar_salir(string $status, string $msg): never {
    header('Location: /inventario/archivados.php?' . $status . '=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    eliminar_salir('err', 'Acción no permitida.');
}

csrf_or_fail('eliminar_activo', true);

$producto_id = (int)($_POST['id'] ?? 0);
if ($producto_id <= 0) {
    eliminar_salir('err', 'Activo inválido.');
}

// La confirmación escrita a mano. Se comparan números y no texto: así da igual
// si escribió «#12» o «12», o si al pegar le quedó un espacio.
$escrito = preg_replace('/[^0-9]+/', '', (string)($_POST['confirmacion'] ?? ''));
if ($escrito === '' || (int)$escrito !== $producto_id) {
    eliminar_salir('err', 'Para eliminar el activo #' . $producto_id
        . ' hay que escribir ese mismo número. No se borró nada.');
}

$tProd = tn('productos');
$st = $conn->prepare("SELECT * FROM `{$tProd}` WHERE id = ? AND archivado = 1 LIMIT 1");
$st->bind_param('i', $producto_id);
$st->execute();
$producto = $st->get_result()->fetch_assoc();
$st->close();

if (!$producto) {
    // Un activo que está en el inventario no se elimina desde acá: primero se
    // archiva. Si además ya no existe, el mensaje es el mismo a propósito.
    eliminar_salir('err', 'El activo no existe o no está archivado. '
        . 'Sólo se puede eliminar lo que ya está en la papelera.');
}

$nombre = trim((string)($producto['modelo'] ?? ''));
if (!empty($producto['marca'])) { $nombre .= ' — ' . $producto['marca']; }
if ($nombre === '') { $nombre = 'Activo #' . $producto_id; }

$conn->begin_transaction();
try {
    /* Lo que colgaba del activo: el sistema operativo, los componentes, los
       puertos de red con sus direcciones, las notas, los campos propios, las
       relaciones con otros activos. La lista de tablas vive en
       core/tenants/papelera.php y no acá, porque el esquema no tiene claves
       foráneas: es una lista escrita a mano, y una lista escrita a mano en
       medio de un endpoint es una lista que se olvida de actualizar. */
    $dependientes = papelera_borrar_dependencias($conn, $producto_id, 'tn');

    /* El historial NO se borra: es el único rastro de que el equipo existió. Se
       le agrega una última fila con todo lo que el activo era, para que las
       filas anteriores no queden colgando de un número sin nombre. */
    /* `realizado_por` guarda el ID del usuario, como en el resto de los endpoints
       que escriben el historial. Acá guardaba el correo, y eso rompía los dos
       reportes: Trazabilidad une `pm.realizado_por = u.id` y filtra con
       `realizado_por != 0`, y comparar un correo contra 0 da falso en MySQL, asi
       que TODA restauración y TODA eliminación se descartaban en silencio. El
       activo se veía archivado para siempre aunque estuviera de vuelta en el
       inventario. El texto legible sigue estando, en `descripcion`. */
    $quien   = (string)$user_id;
    $legible = (string)($_SESSION['user_email'] ?? ($me['username'] ?? ('id:' . $user_id)));
    $desc  = 'Eliminado definitivamente desde la papelera por ' . $legible
           . (!empty($producto['archivado_motivo'])
              ? ' (se había archivado por: ' . $producto['archivado_motivo'] . ')' : '')
           . '. La carpeta de Drive no se borró.';

    $tMov = tn('producto_movimientos');
    $mov  = $conn->prepare(
        "INSERT INTO `{$tMov}`
         (producto_id, accion, descripcion, realizado_por, modelo, marca, servis_tag,
          cantidad, valor_usd, estado, categoria, subcategoria, asignado_a, ubicacion)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $accion = 'Eliminado';
    $bind = [
        ['i', $producto_id],
        ['s', $accion],
        ['s', $desc],
        ['s', $quien],
        ['s', $producto['modelo']],
        ['s', $producto['marca']],
        ['s', $producto['servis_tag']],
        ['i', $producto['cantidad']],
        ['d', $producto['valor_usd']],
        ['s', $producto['estado']],
        ['s', $producto['categoria']],
        ['s', $producto['subcategoria']],
        ['s', $producto['asignado_a']],
        ['s', $producto['ubicacion']],
    ];
    $mov->bind_param(implode('', array_column($bind, 0)), ...array_column($bind, 1));
    $mov->execute();
    $mov->close();

    // Y por último el activo. La condición `archivado = 1` se repite acá a
    // propósito: si alguien lo restauró entre la lectura y el borrado, no se
    // borra un activo que volvió al inventario.
    $del = $conn->prepare("DELETE FROM `{$tProd}` WHERE id = ? AND archivado = 1");
    $del->bind_param('i', $producto_id);
    $del->execute();
    $borradas = $del->affected_rows;
    $del->close();

    if ($borradas !== 1) {
        throw new RuntimeException('El activo cambió de estado mientras se lo eliminaba.');
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    log_event((string)$user_id, 'ERROR',
        "Error al eliminar definitivamente el activo ID {$producto_id}: " . $e->getMessage(), 'archivo');
    eliminar_salir('err', 'No se pudo eliminar el activo: ' . $e->getMessage());
}

log_event((string)$user_id, 'ELIMINAR',
    "Activo ID {$producto_id} ({$nombre}) eliminado definitivamente desde la papelera"
    . " (y {$dependientes} filas que colgaban de él)", 'archivo');

$aviso = '';
if (!empty($producto['carpeta_raiz_drive'])) {
    $aviso = ' Su carpeta de Drive sigue en «Archivados»: si también hay que borrarla, se hace desde Drive.';
}

eliminar_salir('ok', 'Activo eliminado definitivamente: ' . $nombre
    . '. Queda el historial, no el activo.' . $aviso);
