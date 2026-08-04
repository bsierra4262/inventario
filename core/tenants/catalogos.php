<?php
/**
 * Catálogos del tenant: listas gestionadas para campos que antes eran texto libre.
 *
 * Motivo: "Asignado a" y "Ubicación" se cargaban a mano en cada alta. El
 * resultado era el esperado — "Depósito", "deposito", "Dep.", "DEPOSITO" — y
 * los reportes por ubicación o por responsable no agrupaban nada. Ahora son
 * desplegables alimentados por esta tabla; el admin (y sólo el admin) da de
 * alta valores nuevos.
 *
 * Tipos usados hoy: 'asignado_a' (personas o departamentos) y 'ubicacion'.
 * Agregar uno nuevo es sumarlo a CATALOGO_TIPOS.
 */

declare(strict_types=1);

if (!defined('CATALOGO_TIPOS')) {
    define('CATALOGO_TIPOS', [
        'asignado_a' => ['label' => 'Asignado a', 'ayuda' => 'Personas o departamentos a los que se asigna un activo'],
        'ubicacion'  => ['label' => 'Ubicación',  'ayuda' => 'Sedes, oficinas, depósitos o racks donde puede estar un activo'],
    ]);
}

if (!function_exists('catalogo_tipo_valido')) {
function catalogo_tipo_valido(string $tipo): bool {
    return array_key_exists($tipo, CATALOGO_TIPOS);
}
}

/**
 * Valores activos de un catálogo, ordenados para mostrar en un <select>.
 * Cachea por request: la misma página puede pedir el catálogo varias veces.
 *
 * @return string[]
 */
if (!function_exists('catalogo_valores')) {
function catalogo_valores(mysqli $conn, string $tipo, bool $incluirInactivos = false): array {
    if (!catalogo_tipo_valido($tipo)) { return []; }

    static $cache = [];
    $clave = $tipo . ($incluirInactivos ? ':all' : ':activos');
    if (isset($cache[$clave])) { return $cache[$clave]; }

    $out = [];
    try {
        $tabla = tn('catalogos');
        $sql   = "SELECT valor FROM `{$tabla}` WHERE tipo = ?"
               . ($incluirInactivos ? '' : ' AND activo = 1')
               . ' ORDER BY orden ASC, valor ASC';
        $st = $conn->prepare($sql);
        $st->bind_param('s', $tipo);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) { $out[] = (string)$r['valor']; }
        $st->close();
    } catch (Throwable) { /* tabla todavía no migrada */ }

    $cache[$clave] = $out;
    return $out;
}
}

/** Filas completas (para la pantalla de gestión). */
if (!function_exists('catalogo_filas')) {
function catalogo_filas(mysqli $conn, string $tipo): array {
    if (!catalogo_tipo_valido($tipo)) { return []; }
    $out = [];
    try {
        $tabla = tn('catalogos');
        $st = $conn->prepare("SELECT id, valor, activo, orden FROM `{$tabla}` WHERE tipo = ? ORDER BY orden ASC, valor ASC");
        $st->bind_param('s', $tipo);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) { $out[] = $r; }
        $st->close();
    } catch (Throwable) {}
    return $out;
}
}

/** Cuántos activos usan ese valor (para avisar antes de desactivar/borrar). */
if (!function_exists('catalogo_uso')) {
function catalogo_uso(mysqli $conn, string $tipo, string $valor): int {
    if (!catalogo_tipo_valido($tipo)) { return 0; }
    try {
        $tabla = tn('productos');
        // El nombre de columna sale de CATALOGO_TIPOS, nunca del request.
        $col = $tipo;
        $st = $conn->prepare("SELECT COUNT(*) c FROM `{$tabla}` WHERE `{$col}` = ?");
        $st->bind_param('s', $valor);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return (int)($row['c'] ?? 0);
    } catch (Throwable) { return 0; }
}
}

if (!function_exists('catalogo_agregar')) {
function catalogo_agregar(mysqli $conn, string $tipo, string $valor): bool {
    if (!catalogo_tipo_valido($tipo)) { return false; }
    $valor = trim(preg_replace('/\s+/u', ' ', $valor));
    if ($valor === '' || mb_strlen($valor) > 255) { return false; }
    try {
        $tabla = tn('catalogos');
        $st = $conn->prepare("INSERT INTO `{$tabla}` (tipo, valor) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE activo = 1");
        $st->bind_param('ss', $tipo, $valor);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

/**
 * Renombra un valor y arrastra el cambio a los activos que lo usaban, para que
 * no queden huérfanos apuntando a un texto que ya no está en la lista.
 */
if (!function_exists('catalogo_renombrar')) {
function catalogo_renombrar(mysqli $conn, string $tipo, int $id, string $nuevo): bool {
    if (!catalogo_tipo_valido($tipo)) { return false; }
    $nuevo = trim(preg_replace('/\s+/u', ' ', $nuevo));
    if ($nuevo === '' || mb_strlen($nuevo) > 255) { return false; }
    try {
        $tabla = tn('catalogos');
        $st = $conn->prepare("SELECT valor FROM `{$tabla}` WHERE id = ? AND tipo = ? LIMIT 1");
        $st->bind_param('is', $id, $tipo);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) { return false; }
        $viejo = (string)$row['valor'];
        if ($viejo === $nuevo) { return true; }

        $conn->begin_transaction();
        $up = $conn->prepare("UPDATE `{$tabla}` SET valor = ? WHERE id = ? AND tipo = ?");
        $up->bind_param('sis', $nuevo, $id, $tipo);
        $up->execute();
        $up->close();

        $prod = tn('productos');
        $col  = $tipo;
        $up2 = $conn->prepare("UPDATE `{$prod}` SET `{$col}` = ? WHERE `{$col}` = ?");
        $up2->bind_param('ss', $nuevo, $viejo);
        $up2->execute();
        $up2->close();

        $conn->commit();
        return true;
    } catch (Throwable) {
        try { $conn->rollback(); } catch (Throwable) {}
        return false;
    }
}
}

/** Baja lógica: el valor sale del desplegable pero los activos que lo usan lo conservan. */
if (!function_exists('catalogo_activar')) {
function catalogo_activar(mysqli $conn, string $tipo, int $id, bool $activo): bool {
    if (!catalogo_tipo_valido($tipo)) { return false; }
    try {
        $tabla = tn('catalogos');
        $v = $activo ? 1 : 0;
        $st = $conn->prepare("UPDATE `{$tabla}` SET activo = ? WHERE id = ? AND tipo = ?");
        $st->bind_param('iis', $v, $id, $tipo);
        $ok = $st->execute();
        $st->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

/** Borrado definitivo. Se rechaza si hay activos usando el valor. */
if (!function_exists('catalogo_eliminar')) {
function catalogo_eliminar(mysqli $conn, string $tipo, int $id): bool {
    if (!catalogo_tipo_valido($tipo)) { return false; }
    try {
        $tabla = tn('catalogos');
        $st = $conn->prepare("SELECT valor FROM `{$tabla}` WHERE id = ? AND tipo = ? LIMIT 1");
        $st->bind_param('is', $id, $tipo);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) { return false; }
        if (catalogo_uso($conn, $tipo, (string)$row['valor']) > 0) { return false; }

        $del = $conn->prepare("DELETE FROM `{$tabla}` WHERE id = ? AND tipo = ?");
        $del->bind_param('is', $id, $tipo);
        $ok = $del->execute();
        $del->close();
        return (bool)$ok;
    } catch (Throwable) { return false; }
}
}

/**
 * Valida que el valor recibido de un formulario pertenezca al catálogo.
 *
 * Un usuario común no puede inventar valores: si manda algo que no está en la
 * lista, se descarta. Se acepta el vacío (campo opcional) y se acepta el valor
 * que el activo ya tenía, para no romper la edición de activos viejos cargados
 * antes de que existiera el catálogo.
 */
if (!function_exists('catalogo_normalizar')) {
function catalogo_normalizar(mysqli $conn, string $tipo, ?string $valor, ?string $valorActual = null): string {
    $valor = trim((string)$valor);
    if ($valor === '') { return ''; }
    if ($valorActual !== null && $valor === trim($valorActual)) { return $valor; }
    $validos = catalogo_valores($conn, $tipo, true);
    return in_array($valor, $validos, true) ? $valor : '';
}
}
