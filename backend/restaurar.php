<?php
/**
 * Restaura un activo archivado: lo devuelve al inventario y trae su carpeta de
 * Drive de vuelta desde "Archivados".
 *
 * Existe porque archivar era un camino de ida: si alguien daba de baja un activo
 * por error, la única salida era editarlo a mano y destildar `archivado`, y la
 * carpeta quedaba en Drive con el prefijo "[ARCHIVADO]" para siempre.
 *
 * Permiso: asset.restore (por defecto admin y manager).
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
require_permission('asset.restore', $me);

function restaurar_salir(string $status, string $msg): never {
    header('Location: /inventario/archivados.php?' . $status . '=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    restaurar_salir('err', 'Acción no permitida.');
}

csrf_or_fail('restaurar_activo', true);

$producto_id = (int)($_POST['id'] ?? 0);
if ($producto_id <= 0) {
    restaurar_salir('err', 'Activo inválido.');
}

$tProd = tn('productos');
$st = $conn->prepare("SELECT * FROM `{$tProd}` WHERE id = ? AND archivado = 1 LIMIT 1");
$st->bind_param('i', $producto_id);
$st->execute();
$producto = $st->get_result()->fetch_assoc();
$st->close();

if (!$producto) {
    restaurar_salir('err', 'El activo no existe o no está archivado.');
}

$conn->begin_transaction();
try {
    $up = $conn->prepare(
        "UPDATE `{$tProd}`
            SET archivado = 0, fecha_baja = NULL, archivado_en = NULL,
                archivado_por = NULL, archivado_motivo = NULL, drive_padre_previo = NULL
          WHERE id = ?"
    );
    $up->bind_param('i', $producto_id);
    $up->execute();
    $up->close();

    // Dejar rastro en el historial: la papelera no debe borrar el pasado.
    /* `realizado_por` guarda el ID del usuario, como en el resto de los endpoints
       que escriben el historial. Acá guardaba el correo, y eso rompía los dos
       reportes: Trazabilidad une `pm.realizado_por = u.id` y filtra con
       `realizado_por != 0`, y comparar un correo contra 0 da falso en MySQL, asi
       que TODA restauración y TODA eliminación se descartaban en silencio. El
       activo se veía archivado para siempre aunque estuviera de vuelta en el
       inventario. El texto legible sigue estando, en `descripcion`. */
    $quien  = (string)$user_id;
    $legible = (string)($_SESSION['user_email'] ?? ($me['username'] ?? ('id:' . $user_id)));
    $accion = 'Restaurado';
    $desc   = 'Restaurado desde la papelera por ' . $legible
            . (!empty($producto['archivado_motivo']) ? ' (se había archivado por: ' . $producto['archivado_motivo'] . ')' : '');

    $mov = $conn->prepare("
        INSERT INTO `" . tn('producto_movimientos') . "`
        (producto_id, accion, descripcion, realizado_por, modelo, marca, servis_tag,
         cantidad, valor_usd, estado, categoria, subcategoria, asignado_a, ubicacion)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
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
    $tipos  = implode('', array_column($bind, 0));
    $params = array_column($bind, 1);
    $mov->bind_param($tipos, ...$params);
    $mov->execute();
    $mov->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    log_event((string)$user_id, 'ERROR', "Error al restaurar ID {$producto_id}: " . $e->getMessage(), 'archivo');
    restaurar_salir('err', 'No se pudo restaurar el activo: ' . $e->getMessage());
}

log_event((string)$user_id, 'RESTAURAR', "Activo ID {$producto_id} restaurado desde la papelera", 'archivo');

// ── Devolver la carpeta de Drive a su lugar ──────────────────────────────────
$driveMsg  = '';
$carpetaId = (string)($producto['carpeta_raiz_drive'] ?? '');
if ($carpetaId !== '') {
    require_once __DIR__ . '/../core/integraciones/drive.php';
    $res = drive_restaurar_carpeta($conn, $carpetaId, $producto['drive_padre_previo'] ?? null);
    if ($res['ok']) {
        $driveMsg = $res['mensaje'];
        log_event((string)$user_id, 'RESTAURAR_DRIVE', "Carpeta Drive {$carpetaId} devuelta a su lugar", 'archivo');
    } else {
        log_event((string)$user_id, 'WARN',
            "No se pudo mover la carpeta Drive {$carpetaId} al restaurar (Drive no disponible o sin token)", 'archivo');
    }
}

$nombre = trim(($producto['modelo'] ?? '') . ($producto['marca'] ? ' — ' . $producto['marca'] : ''));
restaurar_salir('ok', 'Activo restaurado: ' . ($nombre !== '' ? $nombre : '#' . $producto_id) . '.' . $driveMsg);
