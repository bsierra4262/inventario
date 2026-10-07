<?php
/**
 * Documentos, notas y plantillas de alta.
 *
 * ## Documentos
 *
 * El archivo no se guarda acá: ya hay integración con Drive y OneDrive, y
 * duplicar el almacenamiento sería pelearse con ella. Lo que se guarda es QUÉ
 * es ese archivo —factura, manual, garantía, remito— que es justo lo que hoy no
 * se puede saber: en la carpeta del activo hay seis PDF y ninguno dice cuál es
 * cuál.
 *
 * Un documento puede colgar de varios activos: la misma factura cubre los diez
 * monitores de la compra.
 *
 * ## Notas
 *
 * El historial (`producto_movimientos`) dice qué campo cambió y cuándo. Esto es
 * lo otro: lo que alguien quiere dejar escrito y que no es el cambio de ningún
 * campo. «La fuente hace ruido desde marzo.» «Lo usa Contaduría aunque figure
 * en Sistemas.»
 *
 * ## Plantillas
 *
 * Cargar veinte notebooks iguales es llenar veinte veces los mismos ocho
 * campos. Los valores van en JSON porque son los campos del formulario, y desde
 * la migración 7 esos se definen desde la aplicación: una columna por campo
 * volvería a ser lo que esa migración vino a sacar.
 */

declare(strict_types=1);

if (!defined('DOCUMENTO_TIPOS')) {
    define('DOCUMENTO_TIPOS', [
        'factura'   => 'Factura',
        'remito'    => 'Remito',
        'garantia'  => 'Garantía',
        'contrato'  => 'Contrato',
        'manual'    => 'Manual',
        'foto'      => 'Foto',
        'certificado' => 'Certificado',
        'otro'      => 'Otro',
    ]);
}

if (!function_exists('documentos_disponible')) {
/** ¿Está aplicada la migración 9? */
function documentos_disponible(mysqli $conn): bool {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    try {
        $t = $conn->real_escape_string(tn('documentos'));
        $r = @$conn->query("SHOW TABLES LIKE '{$t}'");
        $cache = (bool)($r && $r->num_rows > 0);
    } catch (Throwable) { $cache = false; }
    return $cache;
}
}

// ═══════════════════════════════════════════════════════════════════════════
//  Documentos
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('documento_guardar')) {
/**
 * Alta o edición de un documento. Devuelve ['ok','id','error'].
 *
 * Tiene que tener enlace o archivo: un documento que no apunta a nada es una
 * fila que no sirve para nada.
 */
function documento_guardar(mysqli $conn, int $id, array $d): array {
    if (!documentos_disponible($conn)) {
        return ['ok' => false, 'error' => 'La base todavía no tiene el esquema de documentos.'];
    }
    $nombre = trim((string)($d['nombre'] ?? ''));
    if ($nombre === '') { return ['ok' => false, 'error' => 'El nombre es obligatorio.']; }

    $tipo = (string)($d['tipo'] ?? 'otro');
    if (!isset(DOCUMENTO_TIPOS[$tipo])) { $tipo = 'otro'; }

    $enlace  = trim((string)($d['enlace'] ?? ''));
    $driveId = trim((string)($d['drive_id'] ?? ''));
    if ($enlace === '' && $driveId === '') {
        return ['ok' => false, 'error' => 'Poné un enlace o elegí un archivo: si no, el documento no apunta a nada.'];
    }
    if ($enlace !== '' && !filter_var($enlace, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'error' => 'El enlace no parece una dirección web válida.'];
    }

    $cols  = ['nombre', 'tipo', 'enlace', 'drive_id', 'nombre_archivo', 'mime', 'tamano', 'etiqueta', 'nota', 'subido_por'];
    $tipos = 'ssssssisss';
    $vals  = [
        $nombre, $tipo, ($enlace ?: null), ($driveId ?: null),
        trim((string)($d['nombre_archivo'] ?? '')) ?: null,
        trim((string)($d['mime'] ?? '')) ?: null,
        isset($d['tamano']) && (int)$d['tamano'] > 0 ? (int)$d['tamano'] : null,
        trim((string)($d['etiqueta'] ?? '')) ?: null,
        trim((string)($d['nota'] ?? '')) ?: null,
        trim((string)($d['subido_por'] ?? '')) ?: null,
    ];
    if (strlen($tipos) !== count($cols) || count($vals) !== count($cols)) {
        return ['ok' => false, 'error' => 'Error interno: la lista de campos no coincide.'];
    }

    try {
        $t = tn('documentos');
        if ($id > 0) {
            $sets = implode(' = ?, ', $cols) . ' = ?';
            $st = $conn->prepare("UPDATE `{$t}` SET {$sets} WHERE id = ?");
            if (!$st) { return ['ok' => false, 'error' => 'No se pudo guardar.']; }
            $vals[] = $id;
            $st->bind_param($tipos . 'i', ...$vals);
            $st->execute();
            $st->close();
            return ['ok' => true, 'id' => $id];
        }
        $marcas = implode(',', array_fill(0, count($cols), '?'));
        $st = $conn->prepare("INSERT INTO `{$t}` (`" . implode('`,`', $cols) . "`) VALUES ({$marcas})");
        if (!$st) { return ['ok' => false, 'error' => 'No se pudo guardar.']; }
        $st->bind_param($tipos, ...$vals);
        $st->execute();
        $nuevo = (int)$st->insert_id;
        $st->close();
        return ['ok' => true, 'id' => $nuevo];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'No se pudo guardar el documento.'];
    }
}
}

if (!function_exists('documentos_listar')) {
/**
 * Todos los documentos cargados, para elegir de una lista.
 *
 * Existía `contratos_listar()` y no su equivalente para documentos: un
 * documento sólo se podía crear desde la ficha de un activo, y no había forma
 * de colgar de un activo nuevo una factura que ya estaba cargada. El alta
 * completa lo necesita.
 */
function documentos_listar(mysqli $conn, int $limite = 500): array {
    if (!documentos_disponible($conn)) { return []; }
    try {
        $do = $conn->real_escape_string(tn('documentos'));
        $rs = $conn->query("SELECT id, nombre, tipo, enlace, drive_id, creado_en
                              FROM `{$do}` ORDER BY creado_en DESC, nombre
                             LIMIT " . max(1, $limite));
        return $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable) { return []; }
}
}

if (!function_exists('documentos_de_activo')) {
/** Los documentos de un activo. */
function documentos_de_activo(mysqli $conn, int $productoId): array {
    if (!documentos_disponible($conn) || $productoId <= 0) { return []; }
    try {
        $do = tn('documentos');
        $da = tn('documento_activo');
        $sql = "SELECT d.* FROM `{$da}` x JOIN `{$do}` d ON d.id = x.documento_id
                 WHERE x.producto_id = ? ORDER BY d.tipo, d.nombre";
        $st = $conn->prepare($sql);
        if (!$st) { return []; }
        $st->bind_param('i', $productoId);
        $st->execute();
        $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $filas;
    } catch (Throwable) { return []; }
}
}

if (!function_exists('documento_vincular')) {
/** Cuelga un documento de un activo. Repetirlo no falla ni duplica. */
function documento_vincular(mysqli $conn, int $documentoId, int $productoId): bool {
    if (!documentos_disponible($conn) || $documentoId <= 0 || $productoId <= 0) { return false; }
    try {
        $st = $conn->prepare('INSERT IGNORE INTO `' . tn('documento_activo') . '`
                              (documento_id, producto_id) VALUES (?,?)');
        if (!$st) { return false; }
        $st->bind_param('ii', $documentoId, $productoId);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('documento_borrar')) {
/**
 * Borra un documento y sus vínculos.
 *
 * No toca el archivo en Drive: lo que se borra es la ficha. Borrar el archivo
 * de la nube desde acá sería una sorpresa desagradable —puede estar compartido,
 * puede estar en la carpeta de otra cosa— y no hay forma de deshacerlo.
 */
function documento_borrar(mysqli $conn, int $id): bool {
    if (!documentos_disponible($conn) || $id <= 0) { return false; }
    try {
        $st = $conn->prepare('DELETE FROM `' . tn('documento_activo') . '` WHERE documento_id = ?');
        if ($st) { $st->bind_param('i', $id); $st->execute(); $st->close(); }
        $st = $conn->prepare('DELETE FROM `' . tn('documentos') . '` WHERE id = ?');
        if (!$st) { return false; }
        $st->bind_param('i', $id);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

// ═══════════════════════════════════════════════════════════════════════════
//  Notas
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('notas_de_activo')) {
/** Las notas de un activo, de la más nueva a la más vieja. */
function notas_de_activo(mysqli $conn, int $productoId): array {
    if (!documentos_disponible($conn) || $productoId <= 0) { return []; }
    try {
        $st = $conn->prepare('SELECT * FROM `' . tn('notas') . '`
                               WHERE producto_id = ? ORDER BY creado_en DESC');
        if (!$st) { return []; }
        $st->bind_param('i', $productoId);
        $st->execute();
        $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $filas;
    } catch (Throwable) { return []; }
}
}

if (!function_exists('nota_agregar')) {
/** Agrega una nota. Devuelve el id o 0. */
function nota_agregar(mysqli $conn, int $productoId, string $texto, string $autor = ''): int {
    if (!documentos_disponible($conn) || $productoId <= 0) { return 0; }
    $texto = trim($texto);
    if ($texto === '') { return 0; }
    try {
        $st = $conn->prepare('INSERT INTO `' . tn('notas') . '` (producto_id, texto, autor) VALUES (?,?,?)');
        if (!$st) { return 0; }
        $a = trim($autor) !== '' ? trim($autor) : null;
        $st->bind_param('iss', $productoId, $texto, $a);
        $st->execute();
        $id = (int)$st->insert_id;
        $st->close();
        return $id;
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('nota_borrar')) {
function nota_borrar(mysqli $conn, int $id): bool {
    if (!documentos_disponible($conn) || $id <= 0) { return false; }
    try {
        $st = $conn->prepare('DELETE FROM `' . tn('notas') . '` WHERE id = ?');
        if (!$st) { return false; }
        $st->bind_param('i', $id);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

// ═══════════════════════════════════════════════════════════════════════════
//  Plantillas de alta
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('plantillas_listar')) {
function plantillas_listar(mysqli $conn, bool $incluirBajas = false): array {
    if (!documentos_disponible($conn)) { return []; }
    try {
        $donde = $incluirBajas ? '' : ' WHERE activo = 1';
        $r = $conn->query('SELECT * FROM `' . tn('plantillas') . '`' . $donde . ' ORDER BY nombre');
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable) { return []; }
}
}

if (!function_exists('plantilla_valores')) {
/**
 * Los valores de una plantilla, ya decodificados.
 *
 * Devuelve array vacío si el JSON está roto, en vez de explotar: una plantilla
 * corrupta no puede dejar sin funcionar el alta de activos.
 */
function plantilla_valores(mysqli $conn, int $id): array {
    if (!documentos_disponible($conn) || $id <= 0) { return []; }
    try {
        $st = $conn->prepare('SELECT valores FROM `' . tn('plantillas') . '` WHERE id = ? AND activo = 1 LIMIT 1');
        if (!$st) { return []; }
        $st->bind_param('i', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) { return []; }
        $v = json_decode((string)($row['valores'] ?? ''), true);
        return is_array($v) ? $v : [];
    } catch (Throwable) { return []; }
}
}

if (!function_exists('plantilla_guardar')) {
/**
 * Alta o edición de una plantilla. Devuelve ['ok','id','error'].
 *
 * Los valores vacíos no se guardan: una plantilla es «lo que se repite», y un
 * campo en blanco no se repite, sólo ensucia.
 */
function plantilla_guardar(mysqli $conn, int $id, array $d, array $valores): array {
    if (!documentos_disponible($conn)) {
        return ['ok' => false, 'error' => 'La base todavía no tiene el esquema de plantillas.'];
    }
    $nombre = trim((string)($d['nombre'] ?? ''));
    if ($nombre === '') { return ['ok' => false, 'error' => 'El nombre es obligatorio.']; }

    $limpios = [];
    foreach ($valores as $k => $v) {
        if (!is_string($k) || !preg_match('/^[a-z0-9_]{1,60}$/i', $k)) { continue; }
        if (is_array($v)) { continue; }
        $v = trim((string)$v);
        if ($v !== '') { $limpios[$k] = $v; }
    }
    $json = json_encode($limpios, JSON_UNESCAPED_UNICODE);

    $desc = trim((string)($d['descripcion'] ?? '')) ?: null;
    $cat  = trim((string)($d['categoria'] ?? '')) ?: null;
    $sub  = trim((string)($d['subcategoria'] ?? '')) ?: null;
    $por  = trim((string)($d['creado_por'] ?? '')) ?: null;

    try {
        $t = tn('plantillas');
        if ($id > 0) {
            $st = $conn->prepare("UPDATE `{$t}` SET nombre=?, descripcion=?, categoria=?,
                                  subcategoria=?, valores=? WHERE id=?");
            if (!$st) { return ['ok' => false, 'error' => 'No se pudo guardar.']; }
            $st->bind_param('sssssi', $nombre, $desc, $cat, $sub, $json, $id);
            $st->execute();
            $st->close();
            return ['ok' => true, 'id' => $id];
        }
        $st = $conn->prepare("INSERT INTO `{$t}` (nombre, descripcion, categoria, subcategoria, valores, creado_por)
                              VALUES (?,?,?,?,?,?)");
        if (!$st) { return ['ok' => false, 'error' => 'No se pudo guardar.']; }
        $st->bind_param('ssssss', $nombre, $desc, $cat, $sub, $json, $por);
        $st->execute();
        $nuevo = (int)$st->insert_id;
        $st->close();
        return ['ok' => true, 'id' => $nuevo];
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) { return ['ok' => false, 'error' => 'Ya hay una plantilla con ese nombre.']; }
        return ['ok' => false, 'error' => 'No se pudo guardar la plantilla.'];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'No se pudo guardar la plantilla.'];
    }
}
}

if (!function_exists('plantilla_baja')) {
/** Da de baja una plantilla. Se desactiva: los activos ya cargados no cambian. */
function plantilla_baja(mysqli $conn, int $id): bool {
    if (!documentos_disponible($conn) || $id <= 0) { return false; }
    try {
        $st = $conn->prepare('UPDATE `' . tn('plantillas') . '` SET activo = 0 WHERE id = ?');
        if (!$st) { return false; }
        $st->bind_param('i', $id);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}
