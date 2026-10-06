<?php
/**
 * Componentes internos de un activo: procesador, memoria, disco, placa de red…
 *
 * GLPI tiene una tabla de catálogo y una de enlace POR CADA tipo:
 * glpi_deviceprocessors + glpi_items_deviceprocessors, glpi_devicememories +
 * glpi_items_devicememories, y así dieciséis veces. Acá son dos tablas con una
 * columna `tipo`, porque los campos que varían entre tipos son cuatro
 * —capacidad, frecuencia, núcleos, MAC— y entran en la misma fila quedando
 * nulos donde no aplican. Veintitrés tablas sólo se justifican si cada tipo
 * tiene columnas propias.
 *
 * El catálogo es compartido: «Intel Core i5-1135G7» se carga una vez y se usa
 * en los cuarenta equipos que lo tienen. Eso es lo que permite preguntar
 * «cuántas máquinas tienen menos de 8 GB», que es la pregunta que se hace antes
 * de comprar memoria.
 */

declare(strict_types=1);

/**
 * Tipos de componente. La clave se guarda; el resto dice cómo se presenta y qué
 * campos tienen sentido para ese tipo, para que el formulario no pida núcleos a
 * una fuente de alimentación.
 */
if (!defined('COMPONENTE_TIPOS')) {
    define('COMPONENTE_TIPOS', [
        'procesador' => ['label' => 'Procesador',    'icono' => 'bi-cpu',
                         'campos' => ['frecuencia_mhz', 'nucleos', 'hilos', 'numero_serie']],
        'memoria'    => ['label' => 'Memoria',       'icono' => 'bi-memory',
                         'campos' => ['capacidad_mb', 'frecuencia_mhz', 'numero_serie']],
        'disco'      => ['label' => 'Disco',         'icono' => 'bi-device-hdd',
                         'campos' => ['capacidad_mb', 'numero_serie']],
        'red'        => ['label' => 'Placa de red',  'icono' => 'bi-ethernet',
                         'campos' => ['mac', 'velocidad', 'numero_serie']],
        'video'      => ['label' => 'Placa de video','icono' => 'bi-gpu-card',
                         'campos' => ['capacidad_mb', 'numero_serie']],
        'fuente'     => ['label' => 'Fuente',        'icono' => 'bi-plug',
                         'campos' => ['numero_serie']],
        'placa'      => ['label' => 'Placa madre',   'icono' => 'bi-motherboard',
                         'campos' => ['numero_serie']],
        'firmware'   => ['label' => 'Firmware / BIOS','icono' => 'bi-bootstrap-reboot',
                         'campos' => ['numero_serie']],
        'otro'       => ['label' => 'Otro',          'icono' => 'bi-puzzle',
                         'campos' => ['capacidad_mb', 'numero_serie']],
    ]);
}

/** Columnas por instancia. El formulario muestra sólo las del tipo. */
if (!defined('COMPONENTE_CAMPOS_INSTANCIA')) {
    define('COMPONENTE_CAMPOS_INSTANCIA', [
        'cantidad'       => ['label' => 'Cantidad',          'tipo' => 'i'],
        'numero_serie'   => ['label' => 'Número de serie',   'tipo' => 's'],
        'capacidad_mb'   => ['label' => 'Capacidad (MB)',    'tipo' => 'i'],
        'frecuencia_mhz' => ['label' => 'Frecuencia (MHz)',  'tipo' => 'i'],
        'nucleos'        => ['label' => 'Núcleos',           'tipo' => 'i'],
        'hilos'          => ['label' => 'Hilos',             'tipo' => 'i'],
        'mac'            => ['label' => 'Dirección MAC',     'tipo' => 's'],
        'nota'           => ['label' => 'Nota',              'tipo' => 's'],
    ]);
}

if (!function_exists('componentes_disponible')) {
/** ¿Está aplicada la migración 8? */
function componentes_disponible(mysqli $conn): bool {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    try {
        $t = $conn->real_escape_string(tn('componentes'));
        $r = @$conn->query("SHOW TABLES LIKE '{$t}'");
        $cache = (bool)($r && $r->num_rows > 0);
    } catch (Throwable) { $cache = false; }
    return $cache;
}
}

if (!function_exists('componente_tipo_valido')) {
function componente_tipo_valido(string $tipo): bool {
    return array_key_exists($tipo, COMPONENTE_TIPOS);
}
}

if (!function_exists('componentes_catalogo')) {
/**
 * El catálogo de componentes, opcionalmente filtrado por tipo.
 *
 * @param bool $incluirBajas true para traer también los dados de baja (la
 *                           pantalla de administración los necesita).
 */
function componentes_catalogo(mysqli $conn, string $tipo = '', bool $incluirBajas = false): array {
    if (!componentes_disponible($conn)) { return []; }
    try {
        $t = tn('componentes');
        $donde = $incluirBajas ? '1=1' : 'activo = 1';
        if ($tipo !== '') {
            if (!componente_tipo_valido($tipo)) { return []; }
            $st = $conn->prepare("SELECT * FROM `{$t}` WHERE {$donde} AND tipo = ? ORDER BY designacion");
            if (!$st) { return []; }
            $st->bind_param('s', $tipo);
        } else {
            $st = $conn->prepare("SELECT * FROM `{$t}` WHERE {$donde} ORDER BY tipo, designacion");
            if (!$st) { return []; }
        }
        $st->execute();
        $filas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $filas;
    } catch (Throwable) { return []; }
}
}

if (!function_exists('componente_alta')) {
/**
 * Da de alta un componente en el catálogo y devuelve su id.
 *
 * Si ya existe uno con el mismo tipo y designación devuelve el que existe, en
 * vez de fallar: dos personas cargando «Kingston DDR4 8GB» desde dos equipos
 * distintos no tienen por qué ver un error.
 */
function componente_alta(mysqli $conn, string $tipo, string $designacion, string $fabricante = '', string $nota = ''): int {
    if (!componentes_disponible($conn) || !componente_tipo_valido($tipo)) { return 0; }
    $designacion = trim($designacion);
    if ($designacion === '') { return 0; }

    try {
        $t = tn('componentes');
        $st = $conn->prepare("SELECT id, activo FROM `{$t}` WHERE tipo = ? AND designacion = ? LIMIT 1");
        if ($st) {
            $st->bind_param('ss', $tipo, $designacion);
            $st->execute();
            $ya = $st->get_result()->fetch_assoc();
            $st->close();
            if ($ya) {
                // Si estaba dado de baja, vuelve: es lo que quiso hacer quien
                // lo está cargando de nuevo.
                if ((int)$ya['activo'] === 0) {
                    $up = $conn->prepare("UPDATE `{$t}` SET activo = 1 WHERE id = ?");
                    if ($up) { $id = (int)$ya['id']; $up->bind_param('i', $id); $up->execute(); $up->close(); }
                }
                return (int)$ya['id'];
            }
        }

        $fab = trim($fabricante) !== '' ? trim($fabricante) : null;
        $not = trim($nota) !== '' ? trim($nota) : null;
        $ins = $conn->prepare("INSERT INTO `{$t}` (tipo, designacion, fabricante, nota) VALUES (?,?,?,?)");
        if (!$ins) { return 0; }
        $ins->bind_param('ssss', $tipo, $designacion, $fab, $not);
        $ins->execute();
        $id = (int)$ins->insert_id;
        $ins->close();
        return $id;
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('componente_baja')) {
/**
 * Da de baja un componente del catálogo.
 *
 * No se borra si algún activo lo tiene puesto: se desactiva y sale del
 * desplegable, pero los activos que lo usan lo siguen mostrando. Es la misma
 * regla que para los catálogos (C6).
 */
function componente_baja(mysqli $conn, int $id): array {
    if (!componentes_disponible($conn) || $id <= 0) { return ['ok' => false, 'error' => 'Componente no válido.']; }
    try {
        $usos = componente_en_uso($conn, $id);
        $t = tn('componentes');
        if ($usos > 0) {
            $st = $conn->prepare("UPDATE `{$t}` SET activo = 0 WHERE id = ?");
            if (!$st) { return ['ok' => false, 'error' => 'No se pudo dar de baja.']; }
            $st->bind_param('i', $id);
            $st->execute();
            $st->close();
            return ['ok' => true, 'desactivado' => true, 'usos' => $usos];
        }
        $st = $conn->prepare("DELETE FROM `{$t}` WHERE id = ?");
        if (!$st) { return ['ok' => false, 'error' => 'No se pudo eliminar.']; }
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        return ['ok' => true, 'desactivado' => false, 'usos' => 0];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'No se pudo completar la baja.']; }
}
}

if (!function_exists('componente_en_uso')) {
/** Cuántos activos tienen puesto este componente. */
function componente_en_uso(mysqli $conn, int $id): int {
    if (!componentes_disponible($conn) || $id <= 0) { return 0; }
    try {
        $st = $conn->prepare('SELECT COUNT(*) c FROM `' . tn('activo_componente') . '` WHERE componente_id = ?');
        if (!$st) { return 0; }
        $st->bind_param('i', $id);
        $st->execute();
        $c = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
        $st->close();
        return $c;
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('componentes_de_activo')) {
/** Los componentes que tiene puesto un activo, con los datos del catálogo. */
function componentes_de_activo(mysqli $conn, int $productoId): array {
    if (!componentes_disponible($conn) || $productoId <= 0) { return []; }
    try {
        $ac = tn('activo_componente');
        $co = tn('componentes');
        $sql = "SELECT a.*, c.tipo, c.designacion, c.fabricante
                  FROM `{$ac}` a
                  JOIN `{$co}` c ON c.id = a.componente_id
                 WHERE a.producto_id = ?
              ORDER BY FIELD(c.tipo, 'procesador','memoria','disco','red','video','placa','fuente','firmware','otro'),
                       c.designacion";
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

if (!function_exists('componente_asignar')) {
/**
 * Pone un componente en un activo.
 *
 * @param array $datos Claves de COMPONENTE_CAMPOS_INSTANCIA.
 */
function componente_asignar(mysqli $conn, int $productoId, int $componenteId, array $datos = []): int {
    if (!componentes_disponible($conn) || $productoId <= 0 || $componenteId <= 0) { return 0; }

    $cols = ['producto_id', 'componente_id'];
    $tipos = 'ii';
    $vals  = [$productoId, $componenteId];

    foreach (COMPONENTE_CAMPOS_INSTANCIA as $campo => $def) {
        if (!array_key_exists($campo, $datos)) { continue; }
        $cols[] = $campo;
        if ($def['tipo'] === 'i') {
            $tipos .= 'i';
            $n = (int)$datos[$campo];
            $vals[] = $n > 0 ? $n : ($campo === 'cantidad' ? 1 : null);
            // Un entero nulo necesita que el tipo sea 's' o mysqli manda 0.
            if ($vals[count($vals) - 1] === null) { $tipos[strlen($tipos) - 1] = 's'; }
        } else {
            $tipos .= 's';
            $v = trim((string)$datos[$campo]);
            $vals[] = $v === '' ? null : $v;
        }
    }

    try {
        $sql = 'INSERT INTO `' . tn('activo_componente') . '` (`' . implode('`,`', $cols) . '`) VALUES ('
             . implode(',', array_fill(0, count($cols), '?')) . ')';
        $st = $conn->prepare($sql);
        if (!$st) { return 0; }
        $st->bind_param($tipos, ...$vals);
        $st->execute();
        $id = (int)$st->insert_id;
        $st->close();
        return $id;
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('componente_quitar')) {
/** Saca un componente de un activo. No toca el catálogo. */
function componente_quitar(mysqli $conn, int $asignacionId): bool {
    if (!componentes_disponible($conn) || $asignacionId <= 0) { return false; }
    try {
        $st = $conn->prepare('DELETE FROM `' . tn('activo_componente') . '` WHERE id = ?');
        if (!$st) { return false; }
        $st->bind_param('i', $asignacionId);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('componente_capacidad_legible')) {
/**
 * 8192 → «8 GB». Los MB se guardan como entero y se muestran en la unidad que
 * la gente usa: nadie dice que un disco tiene 512000 MB.
 */
function componente_capacidad_legible(?int $mb): string {
    if ($mb === null || $mb <= 0) { return ''; }
    if ($mb >= 1024 * 1024) { return rtrim(rtrim(number_format($mb / 1048576, 1, ',', '.'), '0'), ',') . ' TB'; }
    if ($mb >= 1024)        { return rtrim(rtrim(number_format($mb / 1024, 1, ',', '.'), '0'), ',') . ' GB'; }
    return $mb . ' MB';
}
}

if (!function_exists('componentes_resumen_activo')) {
/**
 * Una línea para el listado: «i5-1135G7 · 16 GB · 512 GB».
 *
 * Se arma con un componente por tipo, el primero, porque el listado tiene una
 * columna y no una tabla: el detalle muestra todos.
 */
function componentes_resumen_activo(mysqli $conn, int $productoId): string {
    $partes = [];
    foreach (componentes_de_activo($conn, $productoId) as $c) {
        $tipo = (string)$c['tipo'];
        if (isset($partes[$tipo])) { continue; }
        if ($tipo === 'memoria' || $tipo === 'disco') {
            $cap = componente_capacidad_legible($c['capacidad_mb'] !== null ? (int)$c['capacidad_mb'] : null);
            $partes[$tipo] = $cap !== '' ? $cap : (string)$c['designacion'];
        } elseif ($tipo === 'procesador') {
            $partes[$tipo] = (string)$c['designacion'];
        }
    }
    return implode(' · ', array_values($partes));
}
}
