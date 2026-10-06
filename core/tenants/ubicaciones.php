<?php
/**
 * Ubicaciones del tenant: árbol de lugares con datos de dirección.
 *
 * "Ubicación" era un valor suelto del catálogo general: un string y nada más.
 * Con eso no se puede preguntar "qué hay en la Sede Centro", porque la sede, el
 * piso y el depósito son tres textos sin relación entre sí. En GLPI una
 * ubicación es un árbol —"Sede Centro > Piso 2 > Depósito"— y además lleva
 * dirección, edificio, habitación y coordenadas. Esto replica eso.
 *
 * Cómo convive con lo que ya existía:
 *
 *   - `productos.ubicacion` sigue guardando la **ruta completa** como texto, así
 *     que el listado, los filtros, los reportes, el CSV y el backup siguen
 *     funcionando sin cambios.
 *   - `productos.ubicacion_id` es el enlace preciso, y es el que permite filtrar
 *     por rama del árbol.
 *   - `catalogo_valores($conn, 'ubicacion')` sigue devolviendo una lista plana
 *     de rutas, para que los `<select>` de alta y edición no cambien.
 *
 * El nombre de un nodo es único entre hermanos, no en todo el árbol: puede
 * haber "Depósito" bajo dos sedes distintas sin chocar.
 */

declare(strict_types=1);

if (!defined('UBICACION_SEPARADOR')) {
    define('UBICACION_SEPARADOR', ' > ');
}

if (!function_exists('ubicacion_normalizar_nombre')) {
/** Limpia un nombre de nodo. El separador no puede aparecer dentro del nombre. */
function ubicacion_normalizar_nombre(string $nombre): string {
    $nombre = str_replace(['>', "\0", "\r", "\n"], ' ', $nombre);
    $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? $nombre);
    return mb_strlen($nombre) > 200 ? mb_substr($nombre, 0, 200) : $nombre;
}
}

if (!function_exists('ubicaciones_disponible')) {
/**
 * ¿Ya existe la tabla del árbol?
 *
 * Entre que el código se despliega y que alguien corre `php bin/migrar.php` hay
 * una ventana en la que el código es nuevo y el esquema es viejo. Durante esa
 * ventana `ubicaciones` no existe, y sin este chequeo el desplegable de
 * Ubicación aparecería **vacío** en el alta y en la edición: una regresión
 * visible para el usuario causada por un despliegue a medio aplicar.
 *
 * Con esto, mientras falte migrar se sigue leyendo la lista plana de
 * `catalogos`, que es exactamente lo que había antes. El día que la migración
 * corre, el árbol toma el control solo.
 */
function ubicaciones_disponible(mysqli $conn): bool {
    static $hay = null;
    if ($hay !== null) { return $hay; }
    try {
        $t  = tn('ubicaciones');
        $rs = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
        $hay = (bool)($rs && $rs->num_rows > 0);
    } catch (Throwable) { $hay = false; }
    return $hay;
}
}

if (!function_exists('ubicaciones_todas')) {
/**
 * Todas las ubicaciones, indexadas por id. Cachea por request.
 *
 * @return array<int, array> cada fila con sus columnas + 'hijos' (lista de ids)
 */
function ubicaciones_todas(mysqli $conn, bool $forzar = false): array {
    static $cache = null;
    if ($cache !== null && !$forzar) { return $cache; }

    $out = [];
    try {
        $tabla = tn('ubicaciones');
        $res = $conn->query("SELECT * FROM `{$tabla}` ORDER BY orden ASC, nombre ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['id']       = (int)$r['id'];
                $r['padre_id'] = $r['padre_id'] !== null ? (int)$r['padre_id'] : null;
                $r['activo']   = (int)$r['activo'];
                $r['hijos']    = [];
                $out[$r['id']] = $r;
            }
        }
    } catch (Throwable) { return []; } // tabla todavía no migrada

    foreach ($out as $id => $fila) {
        $pid = $fila['padre_id'];
        if ($pid !== null && isset($out[$pid])) { $out[$pid]['hijos'][] = $id; }
    }

    $cache = $out;
    return $out;
}
}

if (!function_exists('ubicaciones_raices')) {
/** Ids de los nodos sin padre, en orden de presentación. */
function ubicaciones_raices(mysqli $conn): array {
    $todas = ubicaciones_todas($conn);
    $out = [];
    foreach ($todas as $id => $f) {
        if ($f['padre_id'] === null || !isset($todas[$f['padre_id']])) { $out[] = $id; }
    }
    return $out;
}
}

if (!function_exists('ubicaciones_arbol_plano')) {
/**
 * El árbol recorrido en profundidad, para pintarlo con sangría en una lista o
 * en un `<select>`. Cada entrada suma 'nivel'.
 *
 * @return array<int, array>
 */
function ubicaciones_arbol_plano(mysqli $conn, bool $soloActivas = false): array {
    $todas = ubicaciones_todas($conn);
    $out   = [];

    $bajar = function (int $id, int $nivel) use (&$bajar, &$out, $todas, $soloActivas): void {
        $f = $todas[$id] ?? null;
        if ($f === null) { return; }
        if ($soloActivas && $f['activo'] !== 1) { return; }
        $f['nivel'] = $nivel;
        $out[] = $f;
        foreach ($f['hijos'] as $hijo) { $bajar($hijo, $nivel + 1); }
    };

    foreach (ubicaciones_raices($conn) as $raiz) { $bajar($raiz, 0); }
    return $out;
}
}

if (!function_exists('ubicaciones_rutas')) {
/**
 * Lista plana de rutas completas. Es lo que consume el `<select>` de alta y
 * edición, y lo que `catalogo_valores($conn, 'ubicacion')` devuelve.
 *
 * @return string[]
 */
function ubicaciones_rutas(mysqli $conn, bool $incluirInactivas = false): array {
    $out = [];
    foreach (ubicaciones_arbol_plano($conn, !$incluirInactivas) as $f) {
        $out[] = (string)$f['ruta'];
    }
    return $out;
}
}

if (!function_exists('ubicacion_ruta_de')) {
/** Arma la ruta completa de un nodo a partir de su padre. */
function ubicacion_ruta_de(mysqli $conn, ?int $padreId, string $nombre): string {
    $nombre = ubicacion_normalizar_nombre($nombre);
    if ($padreId === null) { return $nombre; }
    $todas = ubicaciones_todas($conn);
    $padre = $todas[$padreId] ?? null;
    return $padre ? ((string)$padre['ruta'] . UBICACION_SEPARADOR . $nombre) : $nombre;
}
}

if (!function_exists('ubicacion_descendientes')) {
/** Ids de todos los descendientes de un nodo (sin incluirlo). */
function ubicacion_descendientes(mysqli $conn, int $id): array {
    $todas = ubicaciones_todas($conn);
    $out   = [];
    $bajar = function (int $n) use (&$bajar, &$out, $todas): void {
        foreach (($todas[$n]['hijos'] ?? []) as $h) { $out[] = $h; $bajar($h); }
    };
    $bajar($id);
    return $out;
}
}

if (!function_exists('ubicacion_agregar')) {
/**
 * Alta de un nodo. Devuelve el id nuevo, o 0 si falló.
 * El nombre tiene que ser único entre hermanos.
 */
function ubicacion_agregar(mysqli $conn, string $nombre, ?int $padreId = null, array $datos = []): int {
    $nombre = ubicacion_normalizar_nombre($nombre);
    if ($nombre === '') { return 0; }

    $todas = ubicaciones_todas($conn);
    if ($padreId !== null && !isset($todas[$padreId])) { $padreId = null; }

    foreach ($todas as $f) {
        if ($f['padre_id'] === $padreId && mb_strtolower((string)$f['nombre']) === mb_strtolower($nombre)) {
            return 0; // ya existe entre hermanos
        }
    }

    try {
        $tabla = tn('ubicaciones');
        $ruta  = ubicacion_ruta_de($conn, $padreId, $nombre);
        $st = $conn->prepare("INSERT INTO `{$tabla}`
            (padre_id, nombre, ruta, direccion, codigo_postal, localidad, provincia, pais, edificio, habitacion, comentarios)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        if (!$st) { return 0; }
        $campos = ['direccion','codigo_postal','localidad','provincia','pais','edificio','habitacion','comentarios'];
        $vals = [];
        foreach ($campos as $k) { $vals[$k] = isset($datos[$k]) ? trim((string)$datos[$k]) : null; }
        $st->bind_param('issssssssss', $padreId, $nombre, $ruta,
            $vals['direccion'], $vals['codigo_postal'], $vals['localidad'], $vals['provincia'],
            $vals['pais'], $vals['edificio'], $vals['habitacion'], $vals['comentarios']);
        $ok = $st->execute();
        $id = $ok ? (int)$conn->insert_id : 0;
        $st->close();
        ubicaciones_todas($conn, true); // invalidar caché
        return $id;
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('ubicacion_actualizar')) {
/** Edita los datos de dirección de un nodo (no su nombre ni su padre). */
function ubicacion_actualizar(mysqli $conn, int $id, array $datos): bool {
    try {
        $tabla = tn('ubicaciones');
        $st = $conn->prepare("UPDATE `{$tabla}` SET direccion=?, codigo_postal=?, localidad=?, provincia=?,
                              pais=?, edificio=?, habitacion=?, latitud=?, longitud=?, comentarios=? WHERE id=?");
        if (!$st) { return false; }
        $d = static fn(string $k) => isset($datos[$k]) && trim((string)$datos[$k]) !== '' ? trim((string)$datos[$k]) : null;
        $lat = isset($datos['latitud'])  && $datos['latitud']  !== '' ? (float)$datos['latitud']  : null;
        $lon = isset($datos['longitud']) && $datos['longitud'] !== '' ? (float)$datos['longitud'] : null;
        $dir=$d('direccion'); $cp=$d('codigo_postal'); $loc=$d('localidad'); $prov=$d('provincia');
        $pais=$d('pais'); $edi=$d('edificio'); $hab=$d('habitacion'); $com=$d('comentarios');
        $st->bind_param('sssssssddsi', $dir, $cp, $loc, $prov, $pais, $edi, $hab, $lat, $lon, $com, $id);
        $ok = $st->execute();
        $st->close();
        ubicaciones_todas($conn, true);
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('ubicacion_renombrar')) {
/**
 * Renombra un nodo y **arrastra el cambio**: recalcula su ruta, la de todos sus
 * descendientes, y actualiza `productos.ubicacion` de los activos afectados.
 *
 * Sin esto, renombrar "Sede Centro" dejaría a los activos apuntando a una ruta
 * que ya no existe — el mismo problema de huérfanos que resuelve
 * catalogo_renombrar() para los catálogos planos.
 */
function ubicacion_renombrar(mysqli $conn, int $id, string $nuevo): bool {
    $nuevo = ubicacion_normalizar_nombre($nuevo);
    if ($nuevo === '') { return false; }

    $todas = ubicaciones_todas($conn);
    $nodo  = $todas[$id] ?? null;
    if ($nodo === null) { return false; }
    if ((string)$nodo['nombre'] === $nuevo) { return true; }

    foreach ($todas as $f) {
        if ($f['id'] !== $id && $f['padre_id'] === $nodo['padre_id']
            && mb_strtolower((string)$f['nombre']) === mb_strtolower($nuevo)) {
            return false; // chocaría con un hermano
        }
    }

    try {
        $tabla = tn('ubicaciones');
        $prod  = tn('productos');
        $conn->begin_transaction();

        $st = $conn->prepare("UPDATE `{$tabla}` SET nombre = ? WHERE id = ?");
        $st->bind_param('si', $nuevo, $id);
        $st->execute();
        $st->close();

        ubicaciones_todas($conn, true);
        if (!ubicacion_recalcular_rutas($conn, $id, $prod, $tabla)) {
            $conn->rollback();
            return false;
        }

        $conn->commit();
        ubicaciones_todas($conn, true);
        return true;
    } catch (Throwable) {
        try { $conn->rollback(); } catch (Throwable) {}
        return false;
    }
}
}

if (!function_exists('ubicacion_mover')) {
/** Cuelga un nodo de otro padre (o de la raíz) y recalcula las rutas. */
function ubicacion_mover(mysqli $conn, int $id, ?int $nuevoPadre): bool {
    $todas = ubicaciones_todas($conn);
    if (!isset($todas[$id])) { return false; }
    if ($nuevoPadre !== null) {
        if (!isset($todas[$nuevoPadre])) { return false; }
        // Un nodo no puede colgar de sí mismo ni de un descendiente suyo.
        if ($nuevoPadre === $id || in_array($nuevoPadre, ubicacion_descendientes($conn, $id), true)) {
            return false;
        }
    }

    try {
        $tabla = tn('ubicaciones');
        $prod  = tn('productos');
        $conn->begin_transaction();

        $st = $conn->prepare("UPDATE `{$tabla}` SET padre_id = ? WHERE id = ?");
        $st->bind_param('ii', $nuevoPadre, $id);
        $st->execute();
        $st->close();

        ubicaciones_todas($conn, true);
        if (!ubicacion_recalcular_rutas($conn, $id, $prod, $tabla)) {
            $conn->rollback();
            return false;
        }

        $conn->commit();
        ubicaciones_todas($conn, true);
        return true;
    } catch (Throwable) {
        try { $conn->rollback(); } catch (Throwable) {}
        return false;
    }
}
}

if (!function_exists('ubicacion_recalcular_rutas')) {
/**
 * Recalcula la ruta de un nodo y la de su subárbol, y propaga el cambio a
 * `productos.ubicacion`. Se llama dentro de una transacción ya abierta.
 */
function ubicacion_recalcular_rutas(mysqli $conn, int $id, string $prod, string $tabla): bool {
    $todas = ubicaciones_todas($conn, true);
    if (!isset($todas[$id])) { return false; }

    $upRuta = $conn->prepare("UPDATE `{$tabla}` SET ruta = ? WHERE id = ?");
    $upProd = $conn->prepare("UPDATE `{$prod}` SET ubicacion = ? WHERE ubicacion_id = ?");
    if (!$upRuta || !$upProd) { return false; }

    // Se recorre de arriba hacia abajo y la ruta de cada hijo se arma con la
    // ruta YA recalculada de su padre, que se lleva en $rutas.
    //
    // La primera versión usaba ubicacion_ruta_de(), que lee la caché estática:
    // al llegar al nieto, el padre todavía tenía la ruta vieja ahí, así que
    // renombrar la raíz no bajaba más de un nivel. El test de integración lo
    // mostró enseguida — renombrar "Sede Centro" dejaba al depósito en
    // "Sede Centro > Piso 2 > Depósito"— y el análisis estático no podía verlo.
    $rutas = [];
    $bajar = function (int $nid) use (&$bajar, &$rutas, $todas, $upRuta, $upProd): void {
        $f      = $todas[$nid];
        $padre  = $f['padre_id'];
        $nombre = (string)$f['nombre'];
        $nueva  = ($padre !== null && isset($rutas[$padre]))
            ? $rutas[$padre] . UBICACION_SEPARADOR . $nombre
            : (($padre !== null && isset($todas[$padre]))
                ? (string)$todas[$padre]['ruta'] . UBICACION_SEPARADOR . $nombre
                : $nombre);

        $rutas[$nid] = $nueva;

        $upRuta->bind_param('si', $nueva, $nid);
        $upRuta->execute();
        $upProd->bind_param('si', $nueva, $nid);
        $upProd->execute();

        foreach ($f['hijos'] as $h) { $bajar($h); }
    };
    $bajar($id);

    $upRuta->close();
    $upProd->close();
    return true;
}
}

if (!function_exists('ubicacion_uso')) {
/** Cuántos activos están en esa ubicación (sin contar los descendientes). */
function ubicacion_uso(mysqli $conn, int $id): int {
    try {
        $prod = tn('productos');
        $st = $conn->prepare("SELECT COUNT(*) c FROM `{$prod}` WHERE ubicacion_id = ?");
        $st->bind_param('i', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return (int)($row['c'] ?? 0);
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('ubicacion_activar')) {
/** Baja lógica: sale del desplegable, los activos que la usan la conservan. */
function ubicacion_activar(mysqli $conn, int $id, bool $activo): bool {
    try {
        $tabla = tn('ubicaciones');
        $v = $activo ? 1 : 0;
        $st = $conn->prepare("UPDATE `{$tabla}` SET activo = ? WHERE id = ?");
        $st->bind_param('ii', $v, $id);
        $ok = $st->execute();
        $st->close();
        ubicaciones_todas($conn, true);
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('ubicacion_eliminar')) {
/** Borrado definitivo. Se rechaza si tiene activos o si tiene hijos. */
function ubicacion_eliminar(mysqli $conn, int $id): bool {
    if (ubicacion_uso($conn, $id) > 0) { return false; }
    $todas = ubicaciones_todas($conn);
    if (!empty($todas[$id]['hijos'])) { return false; }
    try {
        $tabla = tn('ubicaciones');
        $st = $conn->prepare("DELETE FROM `{$tabla}` WHERE id = ?");
        $st->bind_param('i', $id);
        $ok = $st->execute();
        $st->close();
        ubicaciones_todas($conn, true);
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('ubicacion_sincronizar_id')) {
/**
 * Deja `productos.ubicacion_id` apuntando al nodo cuya ruta coincide con el
 * texto guardado en `productos.ubicacion`.
 *
 * Se llama después de dar de alta o editar un activo. Sin esto, `ubicacion_id`
 * sólo lo habría llenado la migración y todo activo nuevo quedaría sin enlace
 * al árbol — es decir, invisible para un filtro por rama.
 *
 * Si la ruta no corresponde a ninguna ubicación (un activo viejo con un texto
 * que ya no está en la lista), queda en NULL: el texto se conserva igual, que
 * es el comportamiento que ya tenían los catálogos.
 */
function ubicacion_sincronizar_id(mysqli $conn, int $productoId, string $ruta): void {
    if ($productoId <= 0) { return; }
    try {
        $id   = ubicacion_id_por_ruta($conn, $ruta);
        $prod = tn('productos');
        $st = $conn->prepare("UPDATE `{$prod}` SET ubicacion_id = ? WHERE id = ?");
        if (!$st) { return; }
        $valor = $id > 0 ? $id : null;
        $st->bind_param('ii', $valor, $productoId);
        $st->execute();
        $st->close();
    } catch (Throwable) { /* la columna todavía no existe: esquema sin migrar */ }
}
}

if (!function_exists('ubicacion_id_por_ruta')) {
/** Busca el id de una ubicación por su ruta completa. 0 si no existe. */
function ubicacion_id_por_ruta(mysqli $conn, string $ruta): int {
    $ruta = trim($ruta);
    if ($ruta === '') { return 0; }
    foreach (ubicaciones_todas($conn) as $id => $f) {
        if ((string)$f['ruta'] === $ruta) { return $id; }
    }
    return 0;
}
}
