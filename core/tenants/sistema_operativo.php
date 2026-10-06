<?php
/**
 * Sistema operativo de un activo.
 *
 * GLPI lo resuelve con siete tablas de catálogo —operatingsystems, versions,
 * servicepacks, architectures, kernels, kernelversions, editions— más una de
 * enlace con la licencia. Acá la fila vive en `activo_so` y los valores que son
 * listas se apoyan en la tabla `catalogos`, que ya existe desde la migración 6
 * y ya tiene alta, baja, renombrado con arrastre y control de uso.
 *
 * Hay una fila por activo, creada por la migración y por el alta, así que
 * guardar es siempre un UPDATE: ninguna pantalla tiene que decidir entre INSERT
 * y UPDATE. Es la misma decisión que se tomó con `activo_infocom`.
 *
 * Por qué importa: sin esto, «qué equipos siguen en Windows 10» se contesta
 * mirando máquina por máquina. Con esto es una consulta.
 */

declare(strict_types=1);

if (!defined('SO_CAMPOS_TEXTO')) {
    define('SO_CAMPOS_TEXTO', [
        'so', 'version', 'edicion', 'arquitectura', 'kernel',
        'service_pack', 'clave_producto', 'numero_licencia',
    ]);
}

if (!defined('SO_CAMPOS_FECHA')) {
    define('SO_CAMPOS_FECHA', ['instalado_en']);
}

/** Arquitecturas que se ofrecen. No es un catálogo: son éstas y no cambian. */
if (!defined('SO_ARQUITECTURAS')) {
    define('SO_ARQUITECTURAS', ['x86_64', 'x86', 'ARM64', 'ARM', 'Otra']);
}

if (!function_exists('so_disponible')) {
/**
 * ¿Está aplicada la migración que crea la tabla?
 *
 * Las pantallas lo consultan para no romperse en un tenant todavía sin migrar:
 * muestran el resto y omiten este bloque, en vez de tirar un error.
 */
function so_disponible(mysqli $conn): bool {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    try {
        $t = $conn->real_escape_string(tn('activo_so'));
        $r = @$conn->query("SHOW TABLES LIKE '{$t}'");
        $cache = (bool)($r && $r->num_rows > 0);
    } catch (Throwable) { $cache = false; }
    return $cache;
}
}

if (!function_exists('so_de')) {
/** La fila del activo. Array vacío si no está migrado o no tiene fila. */
function so_de(mysqli $conn, int $productoId): array {
    if ($productoId <= 0 || !so_disponible($conn)) { return []; }
    try {
        $st = $conn->prepare('SELECT * FROM `' . tn('activo_so') . '` WHERE producto_id = ? LIMIT 1');
        if (!$st) { return []; }
        $st->bind_param('i', $productoId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: [];
    } catch (Throwable) { return []; }
}
}

if (!function_exists('so_asegurar_fila')) {
/** Crea la fila del activo si no existe. */
function so_asegurar_fila(mysqli $conn, int $productoId): void {
    if ($productoId <= 0 || !so_disponible($conn)) { return; }
    try {
        $st = $conn->prepare('INSERT IGNORE INTO `' . tn('activo_so') . '` (producto_id) VALUES (?)');
        if (!$st) { return; }
        $st->bind_param('i', $productoId);
        $st->execute();
        $st->close();
    } catch (Throwable) {}
}
}

if (!function_exists('so_guardar')) {
/**
 * Guarda los datos del sistema operativo de un activo.
 *
 * La lista de columnas y la cadena de tipos de `bind_param` se arman desde el
 * mismo arreglo, nunca a mano: un desajuste entre las dos es un
 * ArgumentCountError fatal en PHP 8, y en este proyecto ya pasó dos veces.
 *
 * @param array $datos Claves de SO_CAMPOS_TEXTO y SO_CAMPOS_FECHA.
 */
function so_guardar(mysqli $conn, int $productoId, array $datos): bool {
    if ($productoId <= 0 || !so_disponible($conn)) { return false; }
    so_asegurar_fila($conn, $productoId);

    $sets = [];
    $tipos = '';
    $valores = [];

    foreach (SO_CAMPOS_TEXTO as $campo) {
        if (!array_key_exists($campo, $datos)) { continue; }
        $sets[] = "`{$campo}` = ?";
        $tipos .= 's';
        $v = trim((string)$datos[$campo]);
        $valores[] = ($v === '' ? null : $v);
    }
    foreach (SO_CAMPOS_FECHA as $campo) {
        if (!array_key_exists($campo, $datos)) { continue; }
        $sets[] = "`{$campo}` = ?";
        $tipos .= 's';
        $v = trim((string)$datos[$campo]);
        // Una fecha vacía es NULL, no '0000-00-00': en modo estricto la segunda
        // hace fallar el UPDATE entero.
        $valores[] = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) ? $v : null;
    }

    if ($sets === []) { return true; }

    try {
        $sql = 'UPDATE `' . tn('activo_so') . '` SET ' . implode(', ', $sets) . ' WHERE producto_id = ?';
        $st = $conn->prepare($sql);
        if (!$st) { return false; }
        $tipos .= 'i';
        $valores[] = $productoId;
        $st->bind_param($tipos, ...$valores);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('so_resumen')) {
/**
 * Una línea legible: «Windows 11 Pro 23H2 (x86_64)».
 *
 * Se arma salteando lo que está vacío, para que no queden paréntesis sueltos ni
 * espacios dobles cuando sólo se cargó el nombre.
 */
function so_resumen(array $fila): string {
    $partes = array_filter([
        trim((string)($fila['so'] ?? '')),
        trim((string)($fila['edicion'] ?? '')),
        trim((string)($fila['version'] ?? '')),
    ], static fn($v) => $v !== '');
    $texto = implode(' ', $partes);

    $arq = trim((string)($fila['arquitectura'] ?? ''));
    if ($texto !== '' && $arq !== '') { return $texto . ' (' . $arq . ')'; }
    return $texto !== '' ? $texto : $arq;
}
}

if (!function_exists('so_conteo_por_sistema')) {
/**
 * Cuántos activos hay por sistema operativo, de mayor a menor.
 *
 * Es la consulta que antes no se podía hacer: «cuántas máquinas quedan en
 * Windows 10» era revisar una por una.
 */
function so_conteo_por_sistema(mysqli $conn): array {
    if (!so_disponible($conn)) { return []; }
    try {
        $so  = tn('activo_so');
        $pro = tn('productos');
        $sql = "SELECT s.so, s.version, COUNT(*) AS cuantos
                  FROM `{$so}` s
                  JOIN `{$pro}` p ON p.id = s.producto_id AND p.archivado = 0
                 WHERE s.so IS NOT NULL AND s.so <> ''
              GROUP BY s.so, s.version
              ORDER BY cuantos DESC, s.so ASC";
        $r = $conn->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable) { return []; }
}
}
