<?php
/**
 * Relaciones entre activos.
 *
 * El monitor que cuelga de una notebook, el disco externo de un servidor, la
 * máquina que reemplazó a otra. GLPI lo parte en dos modelos
 * —Asset_PeripheralAsset para «conectado a» e Item_Item para el resto—; acá es
 * una sola tabla con el tipo de vínculo adentro, porque la diferencia entre los
 * dos es qué tablas se enlazan, y en este esquema todos los activos viven en
 * `productos`.
 *
 * Qué resuelve: al dar de baja un servidor, saber qué dependía de él. Hoy eso
 * se contesta de memoria.
 */

declare(strict_types=1);

/**
 * Tipos de vínculo. `inversa` es cómo se lee desde el otro lado: si A «está
 * conectado a» B, desde B se lee que A «tiene conectado» —y ahí se ve que la
 * relación no es simétrica aunque la tabla guarde una sola fila.
 */
if (!defined('RELACION_TIPOS')) {
    define('RELACION_TIPOS', [
        'conectado' => ['label' => 'Está conectado a', 'inversa' => 'Tiene conectado',
                        'icono' => 'bi-plugin'],
        'parte_de'  => ['label' => 'Es parte de',      'inversa' => 'Contiene',
                        'icono' => 'bi-box-seam'],
        'reemplaza' => ['label' => 'Reemplaza a',      'inversa' => 'Fue reemplazado por',
                        'icono' => 'bi-arrow-left-right'],
        'depende'   => ['label' => 'Depende de',       'inversa' => 'Del que dependen',
                        'icono' => 'bi-diagram-3'],
        'respaldo'  => ['label' => 'Es respaldo de',   'inversa' => 'Tiene como respaldo',
                        'icono' => 'bi-shield-check'],
    ]);
}

if (!function_exists('relaciones_disponible')) {
/** ¿Está aplicada la migración 8? */
function relaciones_disponible(mysqli $conn): bool {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    try {
        $t = $conn->real_escape_string(tn('activo_relacion'));
        $r = @$conn->query("SHOW TABLES LIKE '{$t}'");
        $cache = (bool)($r && $r->num_rows > 0);
    } catch (Throwable) { $cache = false; }
    return $cache;
}
}

if (!function_exists('relacion_tipo_valido')) {
function relacion_tipo_valido(string $tipo): bool {
    return array_key_exists($tipo, RELACION_TIPOS);
}
}

if (!function_exists('relaciones_de')) {
/**
 * Las relaciones de un activo, de los dos lados.
 *
 * Cada fila trae `sentido` ('directa' o 'inversa'), `etiqueta` ya resuelta y
 * los datos del otro activo. La pantalla no tiene que saber de qué lado estaba
 * guardada la fila.
 */
function relaciones_de(mysqli $conn, int $productoId): array {
    if (!relaciones_disponible($conn) || $productoId <= 0) { return []; }
    try {
        $re = tn('activo_relacion');
        $pr = tn('productos');
        $sql = "SELECT r.id, r.tipo, r.nota, 'directa' AS sentido,
                       p.id AS otro_id, p.modelo, p.marca, p.categoria, p.asignado_a, p.archivado
                  FROM `{$re}` r JOIN `{$pr}` p ON p.id = r.destino_id
                 WHERE r.origen_id = ?
                 UNION ALL
                SELECT r.id, r.tipo, r.nota, 'inversa' AS sentido,
                       p.id AS otro_id, p.modelo, p.marca, p.categoria, p.asignado_a, p.archivado
                  FROM `{$re}` r JOIN `{$pr}` p ON p.id = r.origen_id
                 WHERE r.destino_id = ?
              ORDER BY tipo, modelo";
        $st = $conn->prepare($sql);
        if (!$st) { return []; }
        $st->bind_param('ii', $productoId, $productoId);
        $st->execute();
        $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        foreach ($filas as &$f) {
            $def = RELACION_TIPOS[(string)$f['tipo']] ?? null;
            $f['etiqueta'] = $def === null
                ? (string)$f['tipo']
                : ($f['sentido'] === 'directa' ? $def['label'] : $def['inversa']);
            $f['icono'] = $def['icono'] ?? 'bi-link-45deg';
        }
        unset($f);
        return $filas;
    } catch (Throwable) { return []; }
}
}

if (!function_exists('relacion_crear')) {
/**
 * Vincula dos activos.
 *
 * Devuelve ['ok' => bool, 'error' => string]. Rechaza tres casos, y los tres
 * son errores que se cometen enseguida al cargar a mano:
 *
 *   - un activo consigo mismo,
 *   - una pareja que ya está cargada, sin importar de qué lado,
 *   - un activo que no existe.
 *
 * El índice único de la tabla cubre sólo (origen, destino, tipo): la pareja al
 * revés le pasa por al lado, así que se chequea acá.
 */
function relacion_crear(mysqli $conn, int $origenId, int $destinoId, string $tipo, string $nota = ''): array {
    if (!relaciones_disponible($conn)) {
        return ['ok' => false, 'error' => 'La base todavía no tiene el esquema de relaciones.'];
    }
    if ($origenId <= 0 || $destinoId <= 0) {
        return ['ok' => false, 'error' => 'Faltan los activos a vincular.'];
    }
    if ($origenId === $destinoId) {
        return ['ok' => false, 'error' => 'Un activo no se puede vincular consigo mismo.'];
    }
    if (!relacion_tipo_valido($tipo)) {
        return ['ok' => false, 'error' => 'El tipo de relación no es válido.'];
    }

    try {
        $pr = tn('productos');
        $st = $conn->prepare("SELECT COUNT(*) c FROM `{$pr}` WHERE id IN (?,?)");
        if (!$st) { return ['ok' => false, 'error' => 'No se pudo verificar los activos.']; }
        $st->bind_param('ii', $origenId, $destinoId);
        $st->execute();
        $cuantos = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
        $st->close();
        if ($cuantos < 2) { return ['ok' => false, 'error' => 'Alguno de los dos activos no existe.']; }

        $re = tn('activo_relacion');
        $st = $conn->prepare("SELECT id FROM `{$re}`
                               WHERE tipo = ? AND ((origen_id=? AND destino_id=?) OR (origen_id=? AND destino_id=?))
                               LIMIT 1");
        if (!$st) { return ['ok' => false, 'error' => 'No se pudo verificar la relación.']; }
        $st->bind_param('siiii', $tipo, $origenId, $destinoId, $destinoId, $origenId);
        $st->execute();
        $ya = $st->get_result()->fetch_assoc();
        $st->close();
        if ($ya) { return ['ok' => false, 'error' => 'Esos dos activos ya están vinculados así.']; }

        $n = trim($nota) !== '' ? trim($nota) : null;
        $st = $conn->prepare("INSERT INTO `{$re}` (origen_id, destino_id, tipo, nota) VALUES (?,?,?,?)");
        if (!$st) { return ['ok' => false, 'error' => 'No se pudo guardar.']; }
        $st->bind_param('iiss', $origenId, $destinoId, $tipo, $n);
        $st->execute();
        $id = (int)$st->insert_id;
        $st->close();
        return ['ok' => true, 'id' => $id];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'No se pudo guardar la relación.'];
    }
}
}

if (!function_exists('relacion_borrar')) {
/** Quita un vínculo. Funciona desde cualquiera de los dos activos. */
function relacion_borrar(mysqli $conn, int $id): bool {
    if (!relaciones_disponible($conn) || $id <= 0) { return false; }
    try {
        $st = $conn->prepare('DELETE FROM `' . tn('activo_relacion') . '` WHERE id = ?');
        if (!$st) { return false; }
        $st->bind_param('i', $id);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('relaciones_cuantas')) {
/** Cuántos vínculos tiene un activo, contando los dos lados. */
function relaciones_cuantas(mysqli $conn, int $productoId): int {
    if (!relaciones_disponible($conn) || $productoId <= 0) { return 0; }
    try {
        $st = $conn->prepare('SELECT COUNT(*) c FROM `' . tn('activo_relacion') . '`
                               WHERE origen_id = ? OR destino_id = ?');
        if (!$st) { return 0; }
        $st->bind_param('ii', $productoId, $productoId);
        $st->execute();
        $c = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
        $st->close();
        return $c;
    } catch (Throwable) { return 0; }
}
}
