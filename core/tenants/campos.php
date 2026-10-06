<?php
/**
 * Campos específicos por categoría, definidos en datos y no en el esquema.
 *
 * El problema que resuelve: `productos` tenía **catorce columnas** para campos
 * que sólo aplican a una subcategoría —`patchcord_tipo`, `bobina_longitud`,
 * `cintas_generacion`, `subsoftware`…—. Agregar una categoría nueva significaba
 * un `ALTER TABLE`, tocar el alta, la edición, el detalle, los dos export y los
 * dos import. Son ~450 menciones repartidas en trece archivos, y es la misma
 * familia de la que salió el HTTP 500 al editar: listas de columnas y tipos de
 * `bind_param` escritos a mano que se desincronizan.
 *
 * Cómo queda:
 *
 *   - `campos_definicion` dice **qué** campos existen, para qué categoría o
 *     subcategoría, de qué tipo y con qué opciones.
 *   - `campos_valor` guarda el valor por activo.
 *
 * ## Los catorce campos viejos siguen en su columna
 *
 * Cada definición puede declarar una `columna_legacy`. Si la tiene, el valor se
 * lee y se escribe **en esa columna de `productos`**, no en `campos_valor`.
 *
 * Eso es deliberado y es lo que hace que este cambio sea seguro:
 *
 *   - no hay que mover datos, así que no hay riesgo de perderlos;
 *   - los export, los import y el respaldo siguen leyendo las mismas columnas y
 *     no cambian;
 *   - y aun así, **agregar un campo nuevo ya no requiere DDL**: una fila en
 *     `campos_definicion` y listo.
 *
 * Sacar esas columnas es una migración posterior, cuando la de arriba esté
 * probada en producción. Primero se agrega la forma nueva, después se retira la
 * vieja; las dos cosas juntas en un solo paso es como se pierden datos.
 */

declare(strict_types=1);

if (!defined('CAMPO_TIPOS')) {
    define('CAMPO_TIPOS', [
        'texto'    => 'Texto',
        'numero'   => 'Número',
        'fecha'    => 'Fecha',
        'lista'    => 'Lista de opciones',
        'booleano' => 'Sí / No',
    ]);
}

if (!function_exists('campo_tipo_valido')) {
function campo_tipo_valido(string $t): bool {
    return array_key_exists($t, CAMPO_TIPOS);
}
}

if (!function_exists('campo_clave_normalizar')) {
/** La clave es un identificador estable: minúsculas, números y guión bajo. */
function campo_clave_normalizar(string $clave): string {
    $clave = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($clave))) ?? '';
    $clave = trim($clave, '_');
    return mb_strlen($clave) > 60 ? mb_substr($clave, 0, 60) : $clave;
}
}

if (!function_exists('campos_definiciones')) {
/**
 * Todas las definiciones activas, ordenadas. Cachea por request.
 *
 * @return array<int, array>
 */
function campos_definiciones(mysqli $conn, bool $forzar = false): array {
    static $cache = null;
    if ($cache !== null && !$forzar) { return $cache; }

    $out = [];
    try {
        $tabla = tn('campos_definicion');
        $res = $conn->query("SELECT * FROM `{$tabla}` ORDER BY orden ASC, id ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['id']        = (int)$r['id'];
                $r['requerido'] = (int)$r['requerido'];
                $r['activo']    = (int)$r['activo'];
                $r['opciones']  = campos_opciones_lista((string)($r['opciones'] ?? ''));
                $out[] = $r;
            }
        }
    } catch (Throwable) { return []; } // esquema todavía sin migrar

    $cache = $out;
    return $out;
}
}

if (!function_exists('campos_opciones_lista')) {
/** Las opciones se guardan separadas por `|`. */
function campos_opciones_lista(string $raw): array {
    if (trim($raw) === '') { return []; }
    $out = [];
    foreach (explode('|', $raw) as $o) {
        $o = trim($o);
        if ($o !== '') { $out[] = $o; }
    }
    return $out;
}
}

if (!function_exists('campos_para')) {
/**
 * Definiciones que aplican a una categoría/subcategoría.
 *
 * Una definición con `categoria` o `subcategoria` en NULL aplica a todas: así se
 * puede tener un campo global sin repetirlo por cada categoría.
 *
 * @return array<int, array>
 */
function campos_para(mysqli $conn, string $categoria, string $subcategoria, bool $incluirInactivos = false): array {
    $out = [];
    foreach (campos_definiciones($conn) as $d) {
        if (!$incluirInactivos && $d['activo'] !== 1) { continue; }
        $cat = (string)($d['categoria'] ?? '');
        $sub = (string)($d['subcategoria'] ?? '');
        if ($cat !== '' && $cat !== $categoria) { continue; }
        if ($sub !== '' && $sub !== $subcategoria) { continue; }
        if ($cat === '' && $sub === '') { /* global */ }
        $out[] = $d;
    }
    return $out;
}
}

if (!function_exists('campos_valores_de')) {
/**
 * Valores de los campos específicos de un activo, indexados por clave.
 *
 * Mezcla las dos fuentes: las columnas heredadas de `productos` y la tabla
 * `campos_valor`. El llamador no tiene que saber de dónde sale cada uno.
 *
 * @param array $producto Fila de `productos` ya leída (para las columnas legacy).
 * @return array<string, string>
 */
function campos_valores_de(mysqli $conn, int $productoId, array $producto = []): array {
    $out = [];

    foreach (campos_definiciones($conn) as $d) {
        $legacy = (string)($d['columna_legacy'] ?? '');
        if ($legacy !== '' && array_key_exists($legacy, $producto)) {
            $out[(string)$d['clave']] = (string)($producto[$legacy] ?? '');
        }
    }

    if ($productoId > 0) {
        try {
            $tabla = tn('campos_valor');
            $st = $conn->prepare("SELECT clave, valor FROM `{$tabla}` WHERE producto_id = ?");
            if ($st) {
                $st->bind_param('i', $productoId);
                $st->execute();
                $rs = $st->get_result();
                while ($r = $rs->fetch_assoc()) { $out[(string)$r['clave']] = (string)$r['valor']; }
                $st->close();
            }
        } catch (Throwable) {}
    }

    return $out;
}
}

if (!function_exists('campos_normalizar_valor')) {
/**
 * Valida un valor contra su definición. Devuelve '' si no corresponde.
 *
 * Como siempre: el `<select>` del formulario es comodidad visual. Un valor que
 * no está en la lista de opciones se descarta en el servidor.
 */
function campos_normalizar_valor(array $def, ?string $valor): string {
    $valor = trim((string)$valor);
    if ($valor === '') { return ''; }

    switch ((string)$def['tipo']) {
        case 'lista':
            $ops = is_array($def['opciones']) ? $def['opciones'] : campos_opciones_lista((string)$def['opciones']);
            return in_array($valor, $ops, true) ? $valor : '';

        case 'numero':
            return is_numeric($valor) ? (string)(0 + $valor) : '';

        case 'fecha':
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? $valor : '';

        case 'booleano':
            return in_array(strtolower($valor), ['1', 'si', 'sí', 'true', 'on'], true) ? '1' : '0';

        default: // texto
            return mb_strlen($valor) > 1000 ? mb_substr($valor, 0, 1000) : $valor;
    }
}
}

if (!function_exists('campos_guardar')) {
/**
 * Guarda los campos específicos de un activo desde un array tipo $_POST.
 *
 * Sólo toca los campos que aplican a la categoría/subcategoría del activo: así,
 * cambiar un equipo de categoría no arrastra los valores de la anterior a la
 * pantalla nueva, pero tampoco los borra de la base por las dudas.
 *
 * Las definiciones con `columna_legacy` **no se escriben acá**: esas columnas
 * las sigue manejando el UPDATE de `productos`, que es el que ya existe.
 */
function campos_guardar(mysqli $conn, int $productoId, string $categoria, string $subcategoria, array $datos): bool {
    if ($productoId <= 0) { return false; }

    $defs = campos_para($conn, $categoria, $subcategoria);
    if (!$defs) { return true; }

    try {
        $tabla = tn('campos_valor');
        $set = $conn->prepare("INSERT INTO `{$tabla}` (producto_id, clave, valor) VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        $del = $conn->prepare("DELETE FROM `{$tabla}` WHERE producto_id = ? AND clave = ?");
        if (!$set || !$del) { return false; }

        foreach ($defs as $d) {
            if ((string)($d['columna_legacy'] ?? '') !== '') { continue; }
            $clave = (string)$d['clave'];
            $valor = campos_normalizar_valor($d, $datos[$clave] ?? null);

            if ($valor === '') {
                $del->bind_param('is', $productoId, $clave);
                $del->execute();
            } else {
                $set->bind_param('iss', $productoId, $clave, $valor);
                $set->execute();
            }
        }

        $set->close();
        $del->close();
        return true;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('campos_borrar_de')) {
/** Borra los valores de un activo (al eliminarlo definitivamente). */
function campos_borrar_de(mysqli $conn, int $productoId): void {
    if ($productoId <= 0) { return; }
    try {
        $tabla = tn('campos_valor');
        $st = $conn->prepare("DELETE FROM `{$tabla}` WHERE producto_id = ?");
        if (!$st) { return; }
        $st->bind_param('i', $productoId);
        $st->execute();
        $st->close();
    } catch (Throwable) {}
}
}

// ─────────────────────────────────────────────────────────────────────────────
//  ABM de definiciones
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('campo_definicion_agregar')) {
/** Alta de una definición. Devuelve el id nuevo, o 0. */
function campo_definicion_agregar(mysqli $conn, array $d): int {
    $clave = campo_clave_normalizar((string)($d['clave'] ?? ''));
    $etq   = trim((string)($d['etiqueta'] ?? ''));
    $tipo  = (string)($d['tipo'] ?? 'texto');
    if ($clave === '' || $etq === '' || !campo_tipo_valido($tipo)) { return 0; }

    // La clave no puede pisar una columna real de `productos`: si lo hiciera, el
    // campo configurable y la columna heredada se taparían entre sí.
    if (campos_clave_reservada($conn, $clave)) { return 0; }

    try {
        $tabla = tn('campos_definicion');
        $st = $conn->prepare("INSERT INTO `{$tabla}`
            (categoria, subcategoria, clave, etiqueta, tipo, opciones, requerido, orden)
            VALUES (?,?,?,?,?,?,?,?)");
        if (!$st) { return 0; }
        $cat = trim((string)($d['categoria'] ?? '')) ?: null;
        $sub = trim((string)($d['subcategoria'] ?? '')) ?: null;
        $ops = trim((string)($d['opciones'] ?? '')) ?: null;
        $req = !empty($d['requerido']) ? 1 : 0;
        $ord = (int)($d['orden'] ?? 0);
        $st->bind_param('ssssssii', $cat, $sub, $clave, $etq, $tipo, $ops, $req, $ord);
        $ok = $st->execute();
        $id = $ok ? (int)$conn->insert_id : 0;
        $st->close();
        campos_definiciones($conn, true);
        return $id;
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('campos_clave_reservada')) {
/** True si la clave coincide con una columna real de `productos`. */
function campos_clave_reservada(mysqli $conn, string $clave): bool {
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        try {
            $rs = $conn->query('SHOW COLUMNS FROM `' . tn('productos') . '`');
            if ($rs) { while ($r = $rs->fetch_assoc()) { $cols[strtolower((string)$r['Field'])] = true; } }
        } catch (Throwable) {}
    }
    // Las definiciones heredadas sí apuntan a una columna, pero las crea la
    // migración, no esta función.
    return isset($cols[strtolower($clave)]);
}
}

if (!function_exists('campo_definicion_actualizar')) {
/** Edita etiqueta, tipo, opciones, obligatoriedad y orden. La clave no cambia. */
function campo_definicion_actualizar(mysqli $conn, int $id, array $d): bool {
    $etq  = trim((string)($d['etiqueta'] ?? ''));
    $tipo = (string)($d['tipo'] ?? 'texto');
    if ($etq === '' || !campo_tipo_valido($tipo)) { return false; }
    try {
        $tabla = tn('campos_definicion');
        $st = $conn->prepare("UPDATE `{$tabla}` SET etiqueta=?, tipo=?, opciones=?, requerido=?, orden=? WHERE id=?");
        if (!$st) { return false; }
        $ops = trim((string)($d['opciones'] ?? '')) ?: null;
        $req = !empty($d['requerido']) ? 1 : 0;
        $ord = (int)($d['orden'] ?? 0);
        $st->bind_param('sssiii', $etq, $tipo, $ops, $req, $ord, $id);
        $ok = $st->execute();
        $st->close();
        campos_definiciones($conn, true);
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('campo_definicion_activar')) {
function campo_definicion_activar(mysqli $conn, int $id, bool $activo): bool {
    try {
        $tabla = tn('campos_definicion');
        $v = $activo ? 1 : 0;
        $st = $conn->prepare("UPDATE `{$tabla}` SET activo = ? WHERE id = ?");
        if (!$st) { return false; }
        $st->bind_param('ii', $v, $id);
        $ok = $st->execute();
        $st->close();
        campos_definiciones($conn, true);
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

if (!function_exists('campo_definicion_uso')) {
/** Cuántos activos tienen cargado un valor para esa definición. */
function campo_definicion_uso(mysqli $conn, array $def): int {
    try {
        $legacy = (string)($def['columna_legacy'] ?? '');
        if ($legacy !== '') {
            $prod = tn('productos');
            $rs = $conn->query("SELECT COUNT(*) c FROM `{$prod}`
                                 WHERE `{$legacy}` IS NOT NULL AND TRIM(`{$legacy}`) <> ''");
            return $rs ? (int)($rs->fetch_assoc()['c'] ?? 0) : 0;
        }
        $tabla = tn('campos_valor');
        $st = $conn->prepare("SELECT COUNT(*) c FROM `{$tabla}` WHERE clave = ? AND TRIM(valor) <> ''");
        if (!$st) { return 0; }
        $clave = (string)$def['clave'];
        $st->bind_param('s', $clave);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return (int)($row['c'] ?? 0);
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('campo_definicion_eliminar')) {
/**
 * Borra una definición. Se rechaza si está en uso o si es heredada: una
 * definición con `columna_legacy` describe una columna real de `productos` y
 * borrarla dejaría esa columna sin forma de editarse.
 */
function campo_definicion_eliminar(mysqli $conn, int $id): bool {
    $def = null;
    foreach (campos_definiciones($conn) as $d) { if ($d['id'] === $id) { $def = $d; break; } }
    if ($def === null) { return false; }
    if ((string)($def['columna_legacy'] ?? '') !== '') { return false; }
    if (campo_definicion_uso($conn, $def) > 0) { return false; }

    try {
        $tabla = tn('campos_definicion');
        $st = $conn->prepare("DELETE FROM `{$tabla}` WHERE id = ?");
        if (!$st) { return false; }
        $st->bind_param('i', $id);
        $ok = $st->execute();
        $st->close();
        campos_definiciones($conn, true);
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}
