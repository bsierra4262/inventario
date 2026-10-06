<?php
/**
 * Información financiera y administrativa de un activo.
 *
 * Es el bloque que GLPI llama *Infocom*: todo lo que tiene que ver con la
 * compra, la garantía extendida, la amortización y la baja contable, que no es
 * lo mismo que la ficha técnica del equipo.
 *
 * Qué vive acá y qué no:
 *
 *   - `productos` conserva los seis campos que ya tenía —valor, cantidad, fecha
 *     de compra, fecha y período de garantía, fecha de baja—, porque los usan el
 *     listado, los reportes, el CSV y el respaldo. **No se duplican acá.** Dos
 *     fuentes para el mismo dato terminan siempre en que no coinciden.
 *   - `activo_infocom` suma los que faltaban.
 *
 * Lo más concreto que destraba es la amortización: el reporte la calculaba con
 * un `anios` que venía del querystring, el mismo para todos los activos, porque
 * no había dónde guardarla por activo. Un servidor y un patchcord no se amortizan
 * en el mismo plazo.
 *
 * Hay una fila por activo, creada por la migración y por el alta, así que
 * guardar es siempre un UPDATE y ninguna pantalla tiene que decidir entre
 * INSERT y UPDATE.
 */

declare(strict_types=1);

if (!defined('INFOCOM_CAMPOS_TEXTO')) {
    define('INFOCOM_CAMPOS_TEXTO', [
        'numero_pedido', 'numero_remito', 'numero_factura', 'numero_contable',
        'proveedor', 'presupuesto', 'garantia_info', 'criticidad',
    ]);
}

if (!defined('INFOCOM_CAMPOS_FECHA')) {
    define('INFOCOM_CAMPOS_FECHA', [
        'fecha_orden', 'fecha_entrega', 'fecha_puesta_servicio',
        'fecha_ultimo_inventario', 'fecha_decomiso',
    ]);
}

if (!defined('INFOCOM_TIPOS_AMORTIZACION')) {
    define('INFOCOM_TIPOS_AMORTIZACION', [
        'lineal'      => 'Lineal',
        'decreciente' => 'Decreciente',
    ]);
}

if (!function_exists('infocom_de')) {
/**
 * Devuelve la fila de Infocom del activo. Array vacío si el esquema todavía no
 * está migrado o si el activo no tiene fila.
 */
function infocom_de(mysqli $conn, int $productoId): array {
    if ($productoId <= 0) { return []; }
    try {
        $tabla = tn('activo_infocom');
        $st = $conn->prepare("SELECT * FROM `{$tabla}` WHERE producto_id = ? LIMIT 1");
        if (!$st) { return []; }
        $st->bind_param('i', $productoId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: [];
    } catch (Throwable) { return []; }
}
}

if (!function_exists('infocom_asegurar_fila')) {
/** Crea la fila del activo si todavía no existe. */
function infocom_asegurar_fila(mysqli $conn, int $productoId): void {
    if ($productoId <= 0) { return; }
    try {
        $tabla = tn('activo_infocom');
        $st = $conn->prepare("INSERT IGNORE INTO `{$tabla}` (producto_id) VALUES (?)");
        if (!$st) { return; }
        $st->bind_param('i', $productoId);
        $st->execute();
        $st->close();
    } catch (Throwable) {}
}
}

if (!function_exists('infocom_guardar')) {
/**
 * Guarda los datos de Infocom de un activo desde un array tipo $_POST.
 *
 * Los catálogos (proveedor, presupuesto, criticidad) se validan contra su lista
 * en el servidor, igual que "Asignado a" y "Ubicación": el `<select>` del
 * formulario es comodidad visual, no el control.
 *
 * Las fechas vacías entran como NULL y no como '0000-00-00', que es lo que
 * ensucia las comparaciones de fecha en los reportes.
 */
function infocom_guardar(mysqli $conn, int $productoId, array $datos): bool {
    if ($productoId <= 0) { return false; }
    infocom_asegurar_fila($conn, $productoId);

    $actual = infocom_de($conn, $productoId);

    $vals  = [];
    $tipos = '';

    foreach (INFOCOM_CAMPOS_TEXTO as $campo) {
        $v = trim((string)($datos[$campo] ?? ''));
        // Los tres que son catálogo se validan contra la lista.
        if (in_array($campo, ['proveedor', 'presupuesto', 'criticidad'], true)) {
            $v = catalogo_normalizar($conn, $campo, $v, (string)($actual[$campo] ?? ''));
        }
        if (mb_strlen($v) > 255) { $v = mb_substr($v, 0, 255); }
        $vals[]  = $v !== '' ? $v : null;
        $tipos  .= 's';
    }

    foreach (INFOCOM_CAMPOS_FECHA as $campo) {
        $v = trim((string)($datos[$campo] ?? ''));
        $ok = $v !== '' && $v !== '0000-00-00' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
        $vals[]  = $ok ? $v : null;
        $tipos  .= 's';
    }

    // Valor de la extensión de garantía: decimal, nunca negativo.
    $ext = trim((string)($datos['valor_extension_garantia'] ?? ''));
    $vals[]  = $ext !== '' ? max(0.0, (float)$ext) : null;
    $tipos  .= 'd';

    // Amortización.
    $anios = trim((string)($datos['amortizacion_anios'] ?? ''));
    $vals[]  = $anios !== '' ? max(0, (int)$anios) : null;
    $tipos  .= 'i';

    $tipoAm = trim((string)($datos['amortizacion_tipo'] ?? ''));
    $vals[]  = isset(INFOCOM_TIPOS_AMORTIZACION[$tipoAm]) ? $tipoAm : null;
    $tipos  .= 's';

    $coef = trim((string)($datos['amortizacion_coeficiente'] ?? ''));
    $vals[]  = $coef !== '' ? max(0.0, (float)$coef) : null;
    $tipos  .= 'd';

    $vals[]  = !empty($datos['alertas_email']) ? 1 : 0;
    $tipos  .= 'i';

    $com = trim((string)($datos['comentarios_infocom'] ?? ''));
    $vals[]  = $com !== '' ? $com : null;
    $tipos  .= 's';

    $vals[]  = $productoId;
    $tipos  .= 'i';

    // Las columnas salen de las constantes de arriba, nunca del request. Los
    // tipos de bind_param se derivan de la misma lista en el mismo orden: es la
    // regla que dejó el bug del HTTP 500 al editar, donde los tipos estaban
    // escritos a mano y quedaron desfasados de los parámetros.
    $cols = array_merge(INFOCOM_CAMPOS_TEXTO, INFOCOM_CAMPOS_FECHA, [
        'valor_extension_garantia', 'amortizacion_anios', 'amortizacion_tipo',
        'amortizacion_coeficiente', 'alertas_email', 'comentarios',
    ]);
    $set = implode(', ', array_map(static fn($c) => "`{$c}` = ?", $cols));

    if (strlen($tipos) !== count($vals)) { return false; } // red de seguridad

    try {
        $tabla = tn('activo_infocom');
        $st = $conn->prepare("UPDATE `{$tabla}` SET {$set} WHERE producto_id = ?");
        if (!$st) { return false; }
        $st->bind_param($tipos, ...$vals);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('infocom_amortizacion_anios')) {
/**
 * Años de amortización que corresponden a un activo.
 *
 * Usa el valor guardado del activo; si no tiene, cae en el que haya elegido el
 * usuario en el reporte. Antes sólo existía lo segundo, así que todo el
 * inventario se amortizaba en el mismo plazo.
 */
function infocom_amortizacion_anios(array $infocom, int $porDefecto): int {
    $a = (int)($infocom['amortizacion_anios'] ?? 0);
    return $a > 0 ? $a : max(1, $porDefecto);
}
}
